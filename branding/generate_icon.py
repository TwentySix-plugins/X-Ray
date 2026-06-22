#!/usr/bin/env python3
"""
TwentySixx brand icon — final.

Purple gradient squircle with a soft drop shadow and a bold "26" set in
DIN Condensed Bold. Renders at 4x supersampling, exports transparent PNGs at
several sizes.

    python3 generate_icon.py
"""

from PIL import Image, ImageDraw, ImageFilter, ImageFont

# ── Config ──────────────────────────────────────────────────────────────────
SIZE = 1024
SS = 4
S = SIZE * SS

GRAD_TL = (143, 119, 255, 255)   # lighter violet  (top-left)
GRAD_MID = (124, 96, 248, 255)
GRAD_BR = (106, 75, 240, 255)    # deeper purple   (bottom-right)
WHITE = (255, 255, 255, 255)

MARGIN = 74
RADIUS = 224
SHADOW_OFFSET = 16
SHADOW_BLUR = 22
SHADOW_ALPHA = 110

FONT_PATH = "/System/Library/Fonts/Supplemental/DIN Condensed Bold.ttf"
TEXT = "26"
TEXT_HEIGHT_FRAC = 0.52   # glyph height as a fraction of the canvas


def u(v):
    return int(round(v * SS))


def make_gradient(size):
    small = Image.new("RGBA", (3, 3))
    for (x, y, c) in [
        (0, 0, GRAD_TL), (1, 0, GRAD_MID), (2, 0, GRAD_MID),
        (0, 1, GRAD_MID), (1, 1, GRAD_MID), (2, 1, GRAD_BR),
        (0, 2, GRAD_MID), (1, 2, GRAD_BR), (2, 2, GRAD_BR),
    ]:
        small.putpixel((x, y), c)
    return small.resize((size, size), Image.BICUBIC)


def fit_font(path, target_px):
    probe = ImageFont.truetype(path, 400)
    b = probe.getbbox(TEXT)
    h = b[3] - b[1]
    return ImageFont.truetype(path, max(8, int(400 * target_px / h)))


def render():
    base = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    rect = [u(MARGIN), u(MARGIN), S - u(MARGIN), S - u(MARGIN)]

    # Soft drop shadow.
    shadow = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    ImageDraw.Draw(shadow).rounded_rectangle(
        [rect[0], rect[1] + u(SHADOW_OFFSET), rect[2], rect[3] + u(SHADOW_OFFSET)],
        radius=u(RADIUS), fill=(38, 18, 84, SHADOW_ALPHA),
    )
    base = Image.alpha_composite(base, shadow.filter(ImageFilter.GaussianBlur(u(SHADOW_BLUR))))

    # Gradient squircle.
    mask = Image.new("L", (S, S), 0)
    ImageDraw.Draw(mask).rounded_rectangle(rect, radius=u(RADIUS), fill=255)
    badge = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    badge.paste(make_gradient(S), (0, 0), mask)
    base = Image.alpha_composite(base, badge)

    # Centred "26".
    font = fit_font(FONT_PATH, u(SIZE * TEXT_HEIGHT_FRAC))
    layer = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    b = d.textbbox((0, 0), TEXT, font=font)
    tw, th = b[2] - b[0], b[3] - b[1]
    d.text(((S - tw) / 2 - b[0], (S - th) / 2 - b[1]), TEXT, font=font, fill=WHITE)
    base = Image.alpha_composite(base, layer)

    return base.resize((SIZE, SIZE), Image.LANCZOS)


def main():
    final = render()
    final.save("twentysixx-icon.png")
    for size in (512, 256, 128, 64):
        final.resize((size, size), Image.LANCZOS).save(f"twentysixx-icon-{size}.png")
    print("Wrote twentysixx-icon.png (1024) + 512/256/128/64 variants")


if __name__ == "__main__":
    main()
