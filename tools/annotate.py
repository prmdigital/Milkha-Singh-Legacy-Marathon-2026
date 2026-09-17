"""Tags every editable piece of the public pages with a stable data-e key.

    python tools/annotate.py          # tag the pages in place
    python tools/annotate.py --check  # report only, write nothing

The admin website editor saves each change against one of these keys, and the
public pages apply saved changes by the same key. Keys are written into the
HTML once and then kept: re-running this only tags elements that have none, so
a key never moves to a different element when the markup around it changes.

What counts as editable:
  * a text block: the outermost element whose content is text plus inline
    markup only (b, em, span, a, time, br, inline SVG icons). A layout element
    (div, nav, ul, section...) only qualifies when it has text directly inside
    it; otherwise its children are considered instead, so a nav becomes one key
    per link rather than one key for the whole menu.
  * every image
  * a link with no text (the footer social icons), for its address
  * the page <title> and <meta name="description">

Never tagged: the registration and sponsor forms' live parts (fee summary,
status lines, error slots, the age output, submit buttons), the countdown
numbers, and the scrolling sponsor strip, which has its own logo list editor.

Not deployed: build.sh does not copy tools/.
"""

import re
import sys
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PAGES = ["index", "sponsor", "privacy-policy", "refund-policy", "terms-conditions"]

VOID = {"area", "base", "br", "col", "embed", "hr", "img", "input", "link",
        "meta", "source", "track", "wbr"}
INLINE = {"a", "b", "strong", "em", "i", "s", "u", "span", "br", "small", "sup",
          "sub", "time", "abbr", "mark", "code", "q", "cite",
          "svg", "path", "circle", "rect", "g", "line", "polyline", "polygon"}
SVG = {"svg", "path", "circle", "rect", "g", "line", "polyline", "polygon"}
LAYOUT = {"div", "nav", "ul", "ol", "dl", "header", "footer", "section", "article",
          "aside", "main", "figure", "fieldset", "form", "body", "html", "head"}
NEVER = {"script", "style", "select", "option", "optgroup", "textarea", "noscript",
         "button", "input", "output", "template", "iframe"}

# Elements whose content JavaScript writes at runtime. An edit saved over them
# would be overwritten, or would wipe the element the script looks for.
DYNAMIC_IDS = {"countdown", "regSummary", "regAmount", "regNote", "regStatus",
               "regDoneId", "regDoneDue", "regAgeOut", "regSubmit", "spStatus",
               "spDoneRef", "spSubmit", "toTop", "navToggle"}


class Node:
    def __init__(self, tag, attrs, start, end, parent):
        self.tag = tag
        self.attrs = dict(attrs)
        self.start = start          # offset of '<'
        self.end = end              # offset just past the start tag's '>'
        self.parent = parent
        self.children = []
        self.direct_text = False
        self.any_text = False

    def classes(self):
        return (self.attrs.get("class") or "").split()


class Tree(HTMLParser):
    def __init__(self, source):
        super().__init__(convert_charrefs=False)
        self.src = source
        self.line_starts = [0]
        for m in re.finditer(r"\n", source):
            self.line_starts.append(m.end())
        self.root = Node("#root", [], 0, 0, None)
        self.stack = [self.root]

    # Not "offset": HTMLParser already uses that name internally.
    def abs_offset(self):
        line, col = self.getpos()
        return self.line_starts[line - 1] + col

    def _open(self, tag, attrs, void):
        start = self.abs_offset()
        raw = self.get_starttag_text()
        node = Node(tag, attrs, start, start + len(raw), self.stack[-1])
        self.stack[-1].children.append(node)
        if not void:
            self.stack.append(node)

    def handle_starttag(self, tag, attrs):
        self._open(tag, attrs, tag in VOID)

    def handle_startendtag(self, tag, attrs):
        self._open(tag, attrs, True)

    def handle_endtag(self, tag):
        for i in range(len(self.stack) - 1, 0, -1):
            if self.stack[i].tag == tag:
                del self.stack[i:]
                return

    def _text(self, data):
        if data.strip():
            self.stack[-1].direct_text = True
            for n in self.stack:
                n.any_text = True

    def handle_data(self, data):
        self._text(data)

    def handle_entityref(self, name):
        self._text("&" + name + ";")

    def handle_charref(self, name):
        self._text("&#" + name + ";")


def excluded(n):
    if n.tag in NEVER:
        return True
    if n.attrs.get("id") in DYNAMIC_IDS:
        return True
    if any(k in n.attrs for k in ("data-cd", "data-err", "aria-live")):
        return True
    cls = n.classes()
    return "marquee" in cls or "reg__err" in cls


def subtree(n):
    for c in n.children:
        yield c
        yield from subtree(c)


def inline_only(n):
    return all(d.tag in INLINE and not excluded(d) for d in subtree(n))


def scope_of(n):
    p = n.parent
    while p is not None and p.tag != "#root":
        if p.attrs.get("id"):
            return p.attrs["id"]
        if p.tag in ("header", "main", "footer"):
            return p.tag
        p = p.parent
    return "page"


def collect(root):
    """Yields (node, kind) for every element that should carry a key."""
    out = []

    def walk(n, in_head):
        for c in n.children:
            if c.tag == "head":
                walk(c, True)
                continue
            if in_head:
                if c.tag == "title":
                    out.append((c, "meta.title"))
                elif c.tag == "meta" and (c.attrs.get("name") or "") == "description":
                    out.append((c, "meta.description"))
                continue
            if excluded(c):
                continue
            if c.tag == "img":
                out.append((c, "img"))
                continue
            if c.tag in SVG:
                continue
            is_block = (
                c.any_text
                and inline_only(c)
                and (c.tag not in LAYOUT or c.direct_text)
            )
            if is_block:
                out.append((c, "text"))
                continue
            # An icon-only link (the footer social icons): its address is the
            # editable part. A link wrapping an image is walked into instead, so
            # the image itself can be replaced.
            if (c.tag == "a" and not c.any_text and c.attrs.get("href") is not None
                    and all(d.tag in SVG for d in subtree(c))):
                out.append((c, "link"))
                continue
            walk(c, False)

    walk(root, False)
    return out


def annotate(page, write):
    path = ROOT / f"{page}.html"
    src = path.read_text(encoding="utf-8")

    tree = Tree(src)
    tree.feed(src)
    tree.close()

    found = collect(tree.root)
    used = {n.attrs["data-e"] for n, _ in found if n.attrs.get("data-e")}
    counters = {}
    inserts = []
    new = 0

    for node, kind in found:
        if node.attrs.get("data-e"):
            continue
        if kind in ("meta.title", "meta.description"):
            key = kind
        else:
            scope = scope_of(node)
            tag = "img" if kind == "img" else node.tag
            while True:
                counters[(scope, tag)] = counters.get((scope, tag), 0) + 1
                key = f"{scope}.{tag}{counters[(scope, tag)]}"
                if key not in used:
                    break
        used.add(key)
        raw_end = node.end
        close = raw_end - 2 if src[raw_end - 2:raw_end] == "/>" else raw_end - 1
        # Keep " />" tidy: insert before the space that precedes the slash.
        while close > node.start and src[close - 1] == " ":
            close -= 1
        inserts.append((close, f' data-e="{key}"'))
        new += 1

    # The marquee track gets a list marker for the logo editor.
    for m in re.finditer(r'<div class="marquee__track"(?![^>]*data-e-list)', src):
        inserts.append((m.end(), ' data-e-list="marquee"'))

    out = src
    for pos, text in sorted(inserts, reverse=True):
        out = out[:pos] + text + out[pos:]

    stripped = re.sub(r' data-e(?:-list)?="[^"]*"', "", out)
    original = re.sub(r' data-e(?:-list)?="[^"]*"', "", src)
    if stripped != original:
        raise SystemExit(f"{page}: tagging changed something besides the keys; nothing written")

    kinds = {}
    for _, k in found:
        kinds[k] = kinds.get(k, 0) + 1
    print(f"{page:18} {len(found):3} editable  ({new} newly tagged)  {kinds}")

    if write and out != src:
        path.write_text(out, encoding="utf-8", newline="")
    return found


if __name__ == "__main__":
    write = "--check" not in sys.argv
    for p in PAGES:
        annotate(p, write)
