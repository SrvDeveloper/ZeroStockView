"""ZeroStockView のロゴ（AppLogo.vue と同じ図形）を、外部ライブラリなしで PNG / ICO に描き出す。

使い方（src/ で実行）:
    python3 scripts/make-favicon.py public

public/favicon.ico（16・32・48px）と public/apple-touch-icon.png（180px）を上書きする。
ロゴの形を変えたときは、resources/js/components/AppLogo.vue、public/favicon.svg、
このファイルの shapes() の 3 か所を同じ図形にそろえてから実行する。

図形は 32×32 の座標系で、角丸の四角と角丸の枠だけでできている。各ピクセルを 8×8 に分けて
被覆率を求め、アンチエイリアスをかける。
"""
import struct
import sys
import zlib
from pathlib import Path

INK = (0x11, 0x16, 0x1A)
PAPER = (0xE8, 0xEB, 0xEE)
SIGNAL = (0xF5, 0xC4, 0x00)
STROKE = 2.6


def in_rrect(x, y, rx, ry, w, h, r):
    if x < rx or y < ry or x > rx + w or y > ry + h:
        return False
    r = max(0.0, min(r, w / 2, h / 2))
    cx = min(max(x, rx + r), rx + w - r)
    cy = min(max(y, ry + r), ry + h - r)
    return (x - cx) ** 2 + (y - cy) ** 2 <= r * r


def shapes(full_bleed):
    """描く順の (色, 判定関数)。full_bleed は apple-touch-icon 用（地を角丸にしない）"""
    half = STROKE / 2
    frame = (6.5, 5, 19, 22, 4)
    return [
        (INK, lambda x, y: in_rrect(x, y, 0, 0, 32, 32, 0 if full_bleed else 7)),
        (PAPER, lambda x, y: in_rrect(x, y, frame[0] - half, frame[1] - half, frame[2] + STROKE, frame[3] + STROKE, frame[4] + half)
         and not in_rrect(x, y, frame[0] + half, frame[1] + half, frame[2] - STROKE, frame[3] - STROKE, frame[4] - half)),
        (PAPER, lambda x, y: in_rrect(x, y, 10, 9.5, 12, 3, 0.8)),
        (SIGNAL, lambda x, y: in_rrect(x, y, 10, 14.5, 12, 3, 0.8)),
        (PAPER, lambda x, y: in_rrect(x, y, 10, 19.5, 12, 3, 0.8)),
    ]


def render(size, full_bleed=False, samples=8):
    layers = shapes(full_bleed)
    scale = 32 / size
    rows = []
    for py in range(size):
        row = bytearray()
        for px in range(size):
            r = g = b = a = 0.0
            for sy in range(samples):
                for sx in range(samples):
                    x = (px + (sx + 0.5) / samples) * scale
                    y = (py + (sy + 0.5) / samples) * scale
                    color = None
                    for fill, inside in layers:
                        if inside(x, y):
                            color = fill
                    if color:
                        r += color[0]
                        g += color[1]
                        b += color[2]
                        a += 1
            n = samples * samples
            if a:
                row += bytes((round(r / a), round(g / a), round(b / a), round(255 * a / n)))
            else:
                row += bytes((0, 0, 0, 0))
        rows.append(bytes(row))
    return rows


def png(rows):
    size = len(rows)
    raw = b"".join(b"\x00" + row for row in rows)

    def chunk(kind, data):
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data) & 0xFFFFFFFF)

    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", size, size, 8, 6, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(raw, 9)) + chunk(b"IEND", b"")


def ico(images):
    header = struct.pack("<HHH", 0, 1, len(images))
    offset = 6 + 16 * len(images)
    entries, payload = b"", b""
    for size, data in images:
        entries += struct.pack("<BBBBHHII", size % 256, size % 256, 0, 0, 1, 32, len(data), offset)
        payload += data
        offset += len(data)
    return header + entries + payload


def main(out_dir):
    out = Path(out_dir)
    (out / "favicon.ico").write_bytes(ico([(size, png(render(size))) for size in (16, 32, 48)]))
    (out / "apple-touch-icon.png").write_bytes(png(render(180, full_bleed=True, samples=4)))
    print("written:", out / "favicon.ico", out / "apple-touch-icon.png")


if __name__ == "__main__":
    main(sys.argv[1])
