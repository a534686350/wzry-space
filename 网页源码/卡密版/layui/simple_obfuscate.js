// 简单的JavaScript混淆器
const fs = require('fs');
const path = require('path');

// 读取layui.js文件
const inputFile = path.join(__dirname, 'layui.js');
const outputFile = path.join(__dirname, 'script-obfuscated.js');

console.log('正在读取文件...');
let sourceCode = fs.readFileSync(inputFile, 'utf8');

console.log('原始文件大小:', sourceCode.length, '字符');

// 简单的混淆处理
console.log('开始简单混淆...');

// 1. 移除注释
sourceCode = sourceCode.replace(/\/\*[\s\S]*?\*\//g, '');
sourceCode = sourceCode.replace(/\/\/.*$/gm, '');

// 2. 压缩空白字符
sourceCode = sourceCode.replace(/\s+/g, ' ');

// 3. 简单的变量名替换（基础版本）
let counter = 0;
const varMap = new Map();

// 匹配变量名和函数名
const varRegex = /\b(var|let|const)\s+([a-zA-Z_$][a-zA-Z0-9_$]*)/g;
const funcRegex = /\bfunction\s+([a-zA-Z_$][a-zA-Z0-9_$]*)/g;

// 替换变量名
sourceCode = sourceCode.replace(varRegex, (match, keyword, varName) => {
    if (!varMap.has(varName)) {
        varMap.set(varName, '_' + counter.toString(36));
        counter++;
    }
    return keyword + ' ' + varMap.get(varName);
});

// 替换函数名
sourceCode = sourceCode.replace(funcRegex, (match, funcName) => {
    if (!varMap.has(funcName)) {
        varMap.set(funcName, '_' + counter.toString(36));
        counter++;
    }
    return 'function ' + varMap.get(funcName);
});

// 4. 字符串编码（简单的base64编码）
const stringRegex = /(['"])(.*?)\1/g;
sourceCode = sourceCode.replace(stringRegex, (match, quote, content) => {
    if (content.length > 0) {
        return quote + Buffer.from(content).toString('base64') + quote;
    }
    return match;
});

console.log('混淆完成，替换了', varMap.size, '个变量名');

// 写入混淆后的文件
fs.writeFileSync(outputFile, sourceCode);

console.log('混淆完成！');
console.log('输出文件大小:', sourceCode.length, '字符');
console.log('输出文件路径:', outputFile);

// 验证文件是否创建成功
if (fs.existsSync(outputFile)) {
    const stats = fs.statSync(outputFile);
    console.log('文件创建成功，大小:', stats.size, 'bytes');
} else {
    console.error('文件创建失败');
}