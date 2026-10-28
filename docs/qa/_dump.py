import json,re,sys
from pathlib import Path
src=open('build_report2.py',encoding='utf-8').read().split("def thumb")[0].replace("ROOT = Path(__file__).parent","ROOT = Path('.').resolve()")
exec(src)
for i,ch in enumerate(chapters,1):
    out=[f"# CHAPTER {i}: {ch}"]
    for f in sorted((x for x in feats.values() if x['chapter']==ch), key=lambda x:x['n']):
        r=f['res'] or {}
        out.append(f"\n## {f['n']} | {f['name']} | {f['status']}\nFOR: {f.get('text','')}\nDID: {r.get('did','')}")
        for c in r.get('checks',[]): out.append(("  ok: " if c['ok'] else "  NO: ")+c['text'])
        for n in r.get('notes',[]): out.append("  NOTE: "+n)
        if r.get('error'): out.append("  ERR: "+r['error'])
        for s in r.get('shots',[]): out.append(f"  SHOT {s['file']} | {s['caption']} | {s['role']}")
    Path(f'dump/ch{i:02d}.txt').write_text("\n".join(out),encoding='utf-8')
    print(i,ch,len("\n".join(out)))
