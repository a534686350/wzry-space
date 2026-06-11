const JavaScriptObfuscator = require('javascript-obfuscator');
const fs = require('fs');
const path = require('path');

// 读取layui.js文件
const inputFile = path.join(__dirname, 'layui.js');
const outputFile = path.join(__dirname, 'script-obfuscated.js');

console.log('正在读取文件...');
const sourceCode = fs.readFileSync(inputFile, 'utf8');

console.log('开始混淆...');
const obfuscationResult = JavaScriptObfuscator.obfuscate(sourceCode, {
    compact: true,
    controlFlowFlattening: true,
    stringArrayEncoding: ['rc4'],
    stringArray: true,
    stringArrayThreshold: 0.75,
    rotateStringArray: true,
    deadCodeInjection: false,
    debugProtection: false,
    debugProtectionInterval: 0,
    disableConsoleOutput: false,
    identifierNamesGenerator: 'hexadecimal',
    log: false,
    numbersToExpressions: false,
    renameGlobals: false,
    selfDefending: true,
    simplify: true,
    splitStrings: false,
    stringArrayWrappersCount: 1,
    stringArrayWrappersChainedCalls: true,
    stringArrayWrappersParametersMaxCount: 2,
    stringArrayWrappersType: 'variable',
    stringArrayIndexShift: true,
    unicodeEscapeSequence: false
});

console.log('正在写入混淆后的文件...');
fs.writeFileSync(outputFile, obfuscationResult.getObfuscatedCode());

console.log('混淆完成！');
console.log('输入文件大小:', fs.statSync(inputFile).size, 'bytes');
console.log('输出文件大小:', fs.statSync(outputFile).size, 'bytes');
console.log('输出文件路径:', outputFile);