#!/usr/bin/env python3
import json
import os
import sys
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont


def find_font():
    candidates = [
        # Windows paths
        os.path.join(os.environ.get("WINDIR", r"C:\Windows"), "Fonts", "msyh.ttc"),
        os.path.join(os.environ.get("WINDIR", r"C:\Windows"), "Fonts", "msyhbd.ttc"),
        os.path.join(os.environ.get("WINDIR", r"C:\Windows"), "Fonts", "simhei.ttf"),
        os.path.join(os.environ.get("WINDIR", r"C:\Windows"), "Fonts", "simsun.ttc"),
        os.path.join(os.environ.get("WINDIR", r"C:\Windows"), "Fonts", "arial.ttf"),
        # Linux paths
        "/usr/share/fonts/opentype/wqy/wqy-microhei.ttc",
        "/usr/share/fonts/truetype/wqy/wqy-microhei.ttf",
        "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc",
        "/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttf",
    ]
    for candidate in candidates:
        if os.path.exists(candidate):
            return candidate
    return None


def load_font(size):
    font_path = find_font()
    if font_path:
        try:
            return ImageFont.truetype(font_path, size=size)
        except Exception:
            pass
    return ImageFont.load_default()


def text_width(draw, font, text):
    if not text:
        return 0
    box = draw.textbbox((0, 0), text, font=font)
    return box[2] - box[0]


def ellipsize(draw, font, text, max_width):
    text = str(text or "")
    if text_width(draw, font, text) <= max_width:
        return text

    ellipsis = "…"
    lo, hi = 0, len(text)
    best = ellipsis
    while lo <= hi:
        mid = (lo + hi) // 2
        candidate = text[:mid].rstrip() + ellipsis
        if text_width(draw, font, candidate) <= max_width:
            best = candidate
            lo = mid + 1
        else:
            hi = mid - 1
    return best


def main():
    if len(sys.argv) < 3:
        print("Usage: render_balance_alert_table.py <input_json> <output_png>", file=sys.stderr)
        return 2

    input_path = Path(sys.argv[1]).resolve()
    output_path = Path(sys.argv[2]).resolve()

    if not input_path.exists():
        print(f"Input file not found: {input_path}", file=sys.stderr)
        return 3

    with input_path.open("r", encoding="utf-8-sig") as handle:
        snapshot = json.load(handle)

    items = snapshot.get("items", []) or []

    header_font = load_font(18)
    row_font = load_font(16)

    width = 940
    left = 24
    pad_x = 14
    row_h = 42

    # Column widths
    name_w = 310
    current_w = 110
    target_w = 110
    action_w = width - left * 2 - name_w - current_w - target_w - (pad_x * 8) - 2
    if action_w < 200:
        action_w = 200

    header_h = 44
    table_h = header_h + max(1, len(items)) * row_h + 2
    height = 24 + table_h + 24
    height = max(height, 220)

    bg = Image.new("RGBA", (width, height), (13, 18, 31, 255))
    draw = ImageDraw.Draw(bg)

    # Table frame
    table_x = left
    table_y = 12
    table_w = width - left * 2
    draw.rounded_rectangle([table_x, table_y, table_x + table_w, table_y + table_h], radius=10, fill=(15, 22, 39, 255), outline=(58, 73, 95, 255), width=1)

    # Header background
    header_bg = (15, 36, 54, 255)
    draw.rectangle([table_x + 1, table_y + 1, table_x + table_w - 1, table_y + header_h], fill=header_bg)

    # Column boundaries
    x1 = table_x + pad_x
    x2 = x1 + name_w
    x3 = x2 + current_w
    x4 = x3 + target_w
    x5 = table_x + table_w - pad_x

    # Header row text
    header_y = table_y + 13
    draw.text((x1, header_y), "名称", font=header_font, fill=(208, 235, 255, 255))
    draw.text((x2 + 6, header_y), "当前", font=header_font, fill=(208, 235, 255, 255))
    draw.text((x3 + 6, header_y), "目标", font=header_font, fill=(208, 235, 255, 255))
    draw.text((x4 + 6, header_y), "建议", font=header_font, fill=(208, 235, 255, 255))

    # Header separators
    sep_color = (55, 72, 95, 255)
    draw.line((table_x + 1, table_y + header_h, table_x + table_w - 1, table_y + header_h), fill=sep_color, width=2)

    # Rows
    row_top = table_y + header_h
    for idx, item in enumerate(items):
        y0 = row_top + idx * row_h
        y1 = y0 + row_h
        if idx % 2 == 1:
            draw.rectangle([table_x + 1, y0, table_x + table_w - 1, y1], fill=(16, 24, 43, 255))
        # draw a clearer row box so every row has visible separators
        draw.line((table_x + 1, y0, table_x + table_w - 1, y0), fill=(52, 66, 88, 255), width=2)
        draw.line((table_x + 1, y1, table_x + table_w - 1, y1), fill=(52, 66, 88, 255), width=2)

        name = ellipsize(draw, row_font, item.get("name") or item.get("id") or "未命名", name_w)
        current = f"{float(item.get('current_pct', 0) or 0):.2f}%"
        target = f"{float(item.get('target_pct', 0) or 0):.2f}%"
        action = str(item.get("advice_action", "hold"))
        action_text = "买入" if action == "buy" else ("卖出" if action == "sell" else "持有")
        if action == "hold":
            advice = "持有"
        else:
            advice = f"{action_text} ${abs(float(item.get('advice_usd', 0) or 0)):.2f}"
            advice = ellipsize(draw, row_font, advice, action_w)

        text_y = y0 + 11
        draw.text((x1, text_y), name, font=row_font, fill=(235, 242, 250, 255))
        draw.text((x2 + 6, text_y), current, font=row_font, fill=(198, 221, 241, 255))
        draw.text((x3 + 6, text_y), target, font=row_font, fill=(198, 221, 241, 255))
        draw.text((x4 + 6, text_y), advice, font=row_font, fill=(220, 233, 245, 255))

    # Footer note if empty
    if not items:
        draw.text((x1, row_top + 10), "暂无数据", font=row_font, fill=(198, 221, 241, 255))

    # Draw vertical separators last so they stay visible on top of row fills.
    draw.line((x2 - 10, table_y + 1, x2 - 10, table_y + table_h - 1), fill=sep_color, width=2)
    draw.line((x3 - 10, table_y + 1, x3 - 10, table_y + table_h - 1), fill=sep_color, width=2)
    draw.line((x4 - 10, table_y + 1, x4 - 10, table_y + table_h - 1), fill=sep_color, width=2)

    output_path.parent.mkdir(parents=True, exist_ok=True)
    bg.convert("RGB").save(output_path, format="PNG", optimize=True)
    print(f"saved {output_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
