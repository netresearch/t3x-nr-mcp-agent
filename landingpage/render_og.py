#!/usr/bin/env -S uv run --script
# /// script
# requires-python = ">=3.11"
# dependencies = ["cairosvg"]
# ///
"""Render the social preview cards.

One 1200x630 card per language, built from the headline the page itself renders,
so the preview cannot say something the page does not. Output is committed:
og:image has to resolve, and the card only changes when the headline does.

    uv run scripts/render_og.py
"""

from __future__ import annotations

import html
from pathlib import Path

import json

import cairosvg

HERE = Path(__file__).resolve().parent
CONTENT = HERE / "content"
ASSETS = HERE / "assets"

TEAL = "#2F99A4"
ORANGE = "#FF4D00"
BACKGROUND = "#0f1115"
TEXT = "#e8eaed"
MUTED = "#9aa0a6"

SUBTITLE = {
    "en": "Alpha — reads by default, asks before it writes",
    "de": "Alpha – liest standardmäßig, fragt vor dem Schreiben",
}


def wrap(text: str, width: int) -> list[str]:
    lines: list[str] = []
    current = ""
    for word in text.split():
        candidate = f"{current} {word}".strip()
        if len(candidate) > width and current:
            lines.append(current)
            current = word
        else:
            current = candidate
    if current:
        lines.append(current)
    return lines


def card(title: str, subtitle: str) -> str:
    lines = wrap(title, 26)
    tspans = "".join(
        f'<tspan x="72" dy="{0 if index == 0 else 78}">{html.escape(line)}</tspan>'
        for index, line in enumerate(lines)
    )
    # Anchor the block so one-line and three-line titles both sit sensibly.
    baseline = 330 - (len(lines) - 1) * 39

    return f"""<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630">
  <rect width="1200" height="630" fill="{BACKGROUND}"/>
  <rect width="1200" height="10" fill="{TEAL}"/>
  <rect x="72" y="{baseline - 110}" width="120" height="8" fill="{ORANGE}"/>
  <text x="72" y="110" font-family="Raleway, 'Open Sans', sans-serif" font-size="34"
        font-weight="600" fill="{TEAL}">netresearch</text>
  <text x="72" y="{baseline}" font-family="Raleway, 'Open Sans', sans-serif" font-size="66"
        font-weight="700" fill="{TEXT}">{tspans}</text>
  <text x="72" y="558" font-family="'Open Sans', sans-serif" font-size="26"
        fill="{MUTED}">{html.escape(subtitle)}</text>
</svg>"""


def main() -> None:
    for lang in ("en", "de"):
        content = json.loads((CONTENT / f"{lang}.json").read_text(encoding="utf-8"))
        target = ASSETS / f"og-mcp-agent-{lang}.png"
        cairosvg.svg2png(
            bytestring=card(content["hero"]["title"], SUBTITLE[lang]).encode("utf-8"),
            write_to=str(target),
            output_width=1200,
            output_height=630,
        )
        print(f"Wrote {target}")


if __name__ == "__main__":
    main()
