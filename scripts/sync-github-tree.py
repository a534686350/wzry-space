#!/usr/bin/env python3
"""Mirror the local Git HEAD tree to a GitHub branch via the Git Data API.

This is used when normal `git push` to GitHub is unavailable. The remote
GitHub commit SHA may differ from the local commit SHA, but the resulting tree
is verified to match local HEAD exactly.
"""

from __future__ import annotations

import argparse
import base64
import json
import re
import subprocess
import sys
import urllib.error
import urllib.request


def git_text(args: list[str], input_text: str | None = None) -> str:
    return subprocess.check_output(
        ["git", *args],
        input=input_text,
        text=True,
        encoding="utf-8",
    ).strip()


def git_bytes(args: list[str]) -> bytes:
    return subprocess.check_output(["git", *args])


def parse_github_remote(remote: str) -> tuple[str, str]:
    url = git_text(["remote", "get-url", remote])
    match = re.search(r"github\.com[:/]([^/\s]+)/([^/\s]+?)(?:\.git)?$", url)
    if not match:
        raise RuntimeError(f"Remote {remote!r} is not a GitHub repo URL: {url}")
    return match.group(1), match.group(2)


def github_token() -> str:
    query = "protocol=https\nhost=github.com\n\n"
    out = git_text(["credential", "fill"], input_text=query)
    fields = dict(line.split("=", 1) for line in out.splitlines() if "=" in line)
    token = fields.get("password")
    if not token:
        raise RuntimeError("No GitHub credential found in Git Credential Manager")
    return token


class GitHubClient:
    def __init__(self, owner: str, repo: str, token: str) -> None:
        self.api = f"https://api.github.com/repos/{owner}/{repo}"
        self.headers = {
            "Authorization": f"Bearer {token}",
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "wzry-space-sync",
        }

    def request(self, method: str, path: str, data: dict | None = None) -> dict | None:
        body = None
        headers = dict(self.headers)
        if data is not None:
            body = json.dumps(data).encode("utf-8")
            headers["Content-Type"] = "application/json"
        req = urllib.request.Request(
            self.api + path,
            data=body,
            headers=headers,
            method=method,
        )
        try:
            with urllib.request.urlopen(req, timeout=120) as resp:
                raw = resp.read().decode("utf-8")
                return json.loads(raw) if raw else None
        except urllib.error.HTTPError as exc:
            detail = exc.read().decode("utf-8", errors="replace")
            raise RuntimeError(
                f"GitHub API {method} {path} failed: HTTP {exc.code}: {detail}"
            ) from exc


def local_index() -> dict[str, dict[str, str]]:
    raw = git_bytes(["-c", "core.quotepath=false", "ls-files", "-s", "-z"])
    entries: dict[str, dict[str, str]] = {}
    for record in raw.split(b"\0"):
        if not record:
            continue
        meta, path_bytes = record.split(b"\t", 1)
        mode_b, sha_b, stage_b = meta.split(b" ")
        path = path_bytes.decode("utf-8").replace("\\", "/")
        entries[path] = {
            "mode": mode_b.decode("ascii"),
            "sha": sha_b.decode("ascii"),
            "stage": stage_b.decode("ascii"),
        }
    return entries


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--remote", default="github")
    parser.add_argument("--branch", default="main")
    args = parser.parse_args()

    owner, repo = parse_github_remote(args.remote)
    client = GitHubClient(owner, repo, github_token())

    local_head = git_text(["rev-parse", "HEAD"])
    local_tree = git_text(["rev-parse", "HEAD^{tree}"])
    message = git_text(["log", "-1", "--pretty=%B"])
    author_name = git_text(["log", "-1", "--pretty=%an"])
    author_email = git_text(["log", "-1", "--pretty=%ae"])
    author_date = git_text(["log", "-1", "--pretty=%aI"])
    committer_name = git_text(["log", "-1", "--pretty=%cn"])
    committer_email = git_text(["log", "-1", "--pretty=%ce"])
    committer_date = git_text(["log", "-1", "--pretty=%cI"])

    ref = client.request("GET", f"/git/ref/heads/{args.branch}")
    assert ref is not None
    remote_head = ref["object"]["sha"]
    remote_commit = client.request("GET", f"/git/commits/{remote_head}")
    assert remote_commit is not None
    remote_tree_sha = remote_commit["tree"]["sha"]

    print(f"GitHub current head: {remote_head[:7]}")
    print(f"Local current head:  {local_head[:7]}")
    if remote_tree_sha == local_tree:
        print("GitHub content tree is already identical to local HEAD.")
        return 0

    remote_tree = client.request("GET", f"/git/trees/{remote_tree_sha}?recursive=1")
    assert remote_tree is not None
    if remote_tree.get("truncated"):
        raise RuntimeError("GitHub recursive tree response was truncated")

    remote_files = {
        item["path"]: item
        for item in remote_tree.get("tree", [])
        if item.get("type") == "blob"
    }
    local_files = local_index()
    tree_entries: list[dict] = []
    changed = 0
    deleted = 0

    for path in sorted(local_files):
        info = local_files[path]
        remote = remote_files.get(path)
        if remote and remote.get("sha") == info["sha"] and remote.get("mode") == info["mode"]:
            continue
        content = git_bytes(["cat-file", "-p", info["sha"]])
        blob = client.request(
            "POST",
            "/git/blobs",
            {
                "content": base64.b64encode(content).decode("ascii"),
                "encoding": "base64",
            },
        )
        assert blob is not None
        tree_entries.append(
            {
                "path": path,
                "mode": info["mode"],
                "type": "blob",
                "sha": blob["sha"],
            }
        )
        changed += 1
        print(f"Update: {path}")

    for path in sorted(set(remote_files) - set(local_files)):
        tree_entries.append(
            {
                "path": path,
                "mode": remote_files[path].get("mode", "100644"),
                "type": "blob",
                "sha": None,
            }
        )
        deleted += 1
        print(f"Delete: {path}")

    if not tree_entries:
        raise RuntimeError("No file-level differences found, but tree SHAs differ")

    tree = client.request(
        "POST",
        "/git/trees",
        {
            "base_tree": remote_tree_sha,
            "tree": tree_entries,
        },
    )
    assert tree is not None

    commit = client.request(
        "POST",
        "/git/commits",
        {
            "message": message,
            "tree": tree["sha"],
            "parents": [remote_head],
            "author": {"name": author_name, "email": author_email, "date": author_date},
            "committer": {
                "name": committer_name,
                "email": committer_email,
                "date": committer_date,
            },
        },
    )
    assert commit is not None

    client.request(
        "PATCH",
        f"/git/refs/heads/{args.branch}",
        {
            "sha": commit["sha"],
            "force": False,
        },
    )

    print(f"Updated files: {changed}, deleted files: {deleted}")
    print(f"GitHub updated head: {commit['sha']}")
    print(f"GitHub updated tree: {tree['sha']}")
    if tree["sha"] != local_tree:
        raise RuntimeError(
            f"GitHub tree {tree['sha']} does not match local tree {local_tree}"
        )
    print("GitHub tree now matches local HEAD exactly.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
