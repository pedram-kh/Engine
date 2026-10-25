import json,sys
from PIL import Image
label=sys.argv[1]; rec=json.loads(open(f'/tmp/align-{label}.json').read())
im=Image.open(f'/tmp/align-{label}.png').convert('RGB'); W,H=im.size; k=W/rec['vw']
# sheet background = pixel just inside the card, mid-row between title and checkbox
bg=im.getpixel((int(8*k), int(rec['bands']['title'][1]*k)+2))
def ink(y0,y1):
    best=None
    for x in range(int(2*k), int(60*k)):
        for y in range(int(y0*k), int(y1*k)):
            r,g,b=im.getpixel((x,y))
            if abs(r-bg[0])+abs(g-bg[1])+abs(b-bg[2])>90: best=x;break
        if best is not None: break
    return round(best/k,2) if best is not None else None
print(label, 'bg',bg, {n:ink(*v) for n,v in rec['bands'].items()})
