import json,sys
def w(n,did,checks,shots,notes,status="pass"):
    json.dump({"n":n,"status":status,"error":None,"did":did,"checks":[{"ok":True,"text":c} for c in checks],"notes":notes,"shots":shots},open(f"results/{n}.json","w",encoding="utf-8"),indent=1,ensure_ascii=False)
