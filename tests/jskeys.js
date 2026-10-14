// Liệt kê khóa dịch trong app.js; nếu truyền file từ điển thì báo khóa còn thiếu.
const fs = require('fs');
const src = fs.readFileSync(process.argv[2], 'utf8');
const keys = new Set();
for (const m of src.matchAll(/\bt\('((?:[^'\\]|\\.)*)'/g)) keys.add(m[1].replace(/\\'/g, "'"));
const df = src.slice(src.indexOf('var DEST_TYPES'), src.indexOf('function viewDestinations'));
for (const m of df.matchAll(/'([^']*[À-ỹ][^']*)'/g)) keys.add(m[1]);
['Ổ cứng máy chủ', 'Sao lưu hằng ngày'].forEach(k => keys.add(k));
const arr = [...keys].filter(k => /[À-ỹ]/.test(k) || /\{/.test(k));
if (process.argv[3]) {
    global.window = {};
    eval(fs.readFileSync(process.argv[3], 'utf8'));
    const dict = window.WNB_EN;
    const missing = arr.filter(k => !(k in dict));
    const unused = Object.keys(dict).filter(k => !keys.has(k));
    console.log('keys', arr.length, 'missing', missing.length, 'unused', unused.length);
    missing.forEach(k => console.log('MISSING: ' + k));
    unused.forEach(k => console.log('UNUSED: ' + k));
    // biến {x} phải khớp giữa 2 ngôn ngữ
    for (const k of arr) if (dict[k]) {
        const a = (k.match(/\{\w+\}/g) || []).sort().join(), b = (dict[k].match(/\{\w+\}/g) || []).sort().join();
        if (a !== b) console.log('VARS MISMATCH: ' + k);
    }
} else {
    console.log(arr.length);
    console.log(arr.join('\n'));
}
