import sys as _oj_sys
_oj_stdout = _oj_sys.stdout
_oj_sys.stdout = _oj_sys.stderr
try:
    import tkinter as _oj_tk
except ImportError:
    import types as _oj_types
    _oj_tk = _oj_types.ModuleType("tkinter")
    for _oj_name in ("Frame", "Tk", "Canvas", "Scrollbar", "PhotoImage"):
        setattr(_oj_tk, _oj_name, type(_oj_name, (object,), {}))
    _oj_tk.TclError = type("TclError", (Exception,), {})
    _oj_tk.HORIZONTAL = "horizontal"
    _oj_tk.ROUND = "round"
    _oj_tk.SUNKEN = "sunken"
    _oj_tk.simpledialog = _oj_types.ModuleType("tkinter.simpledialog")
    _oj_sys.modules["tkinter"] = _oj_tk
    _oj_sys.modules["tkinter.simpledialog"] = _oj_tk.simpledialog
import turtle as _oj_turtle

def _oj_rgb(color):
    from PIL import ImageColor
    try:
        return ImageColor.getrgb(color)[:3]
    except ValueError:
        raise _oj_tk.TclError("unknown color name " + repr(color))

class _OJCanvas(object):
    def __init__(self, width, height):
        self._size = [int(width), int(height)]
        self._bg = "white"
        self._items = {}
        self._order = []
        self._next = 1

    def _new(self, kind, coords, options):
        item = self._next
        self._next += 1
        self._items[item] = {"kind": kind, "coords": [float(v) for v in coords], "options": dict(options)}
        self._order.append(item)
        return item

    def __getitem__(self, key):
        return self.cget(key)

    def cget(self, key):
        if key == "width":
            return self._size[0]
        if key == "height":
            return self._size[1]
        if key == "bg":
            return self._bg
        return ""

    def config(self, **options):
        if "bg" in options:
            self._bg = options["bg"]
        if "width" in options:
            self._size[0] = int(options["width"])
        if "height" in options:
            self._size[1] = int(options["height"])

    configure = config

    def create_polygon(self, coords, **options):
        return self._new("polygon", coords, options)

    def create_line(self, *coords, **options):
        return self._new("line", coords, options)

    def create_text(self, x, y, **options):
        return self._new("text", (x, y), options)

    def create_image(self, x, y, **options):
        return self._new("image", (x, y), options)

    def coords(self, item, *values):
        entry = self._items[item]
        if not values:
            return list(entry["coords"])
        flat = []
        for value in values:
            if isinstance(value, (tuple, list)):
                flat.extend(value)
            else:
                flat.append(value)
        entry["coords"] = [float(v) for v in flat]

    def itemconfigure(self, item, **options):
        if item in self._items:
            self._items[item]["options"].update(options)

    itemconfig = itemconfigure

    def delete(self, item):
        if item == "all":
            self._items = {}
            self._order = []
        elif item in self._items:
            del self._items[item]
            self._order.remove(item)

    def tag_raise(self, item, above=None):
        if item in self._items:
            self._order.remove(item)
            self._order.append(item)

    def tag_lower(self, item, below=None):
        if item in self._items:
            self._order.remove(item)
            self._order.insert(0, item)

    def type(self, item):
        return self._items[item]["kind"]

    def find_all(self):
        return tuple(self._order)

    def bbox(self, item):
        entry = self._items[item]
        if entry["kind"] == "text":
            width, height = _oj_text_size(entry["options"].get("text", ""), entry["options"].get("font"))
            x, y = entry["coords"][0], entry["coords"][1]
            anchor = entry["options"].get("anchor", "center")
            left = x - width if "e" in anchor else (x if "w" in anchor else x - width / 2.0)
            top = y - height if "s" in anchor else (y if "n" in anchor else y - height / 2.0)
            return (left, top, left + width, top + height)
        values = entry["coords"]
        xs = values[0::2] or [0.0]
        ys = values[1::2] or [0.0]
        return (min(xs), min(ys), max(xs), max(ys))

    def winfo_width(self):
        return self._size[0]

    def winfo_height(self):
        return self._size[1]

    def winfo_rgb(self, color):
        red, green, blue = _oj_rgb(color)
        return (red * 257, green * 257, blue * 257)

    def canvasx(self, value, gridspacing=None):
        return value

    def canvasy(self, value, gridspacing=None):
        return value

    def update(self):
        pass

    def after(self, *args):
        pass

    def after_idle(self, *args):
        pass

    def bind(self, *args, **options):
        pass

    def unbind(self, *args, **options):
        pass

    def tag_bind(self, *args, **options):
        pass

    def tag_unbind(self, *args, **options):
        pass

    def focus_force(self):
        pass

    def reset(self, *args, **options):
        pass

class _OJScreen(_oj_turtle.TurtleScreen):
    def _blankimage(self):
        image = _oj_tk.PhotoImage.__new__(_oj_tk.PhotoImage)
        image.name = None
        return image

    def _image(self, filename):
        raise _oj_turtle.TurtleGraphicsError("image files are not supported")

    def _ontimer(self, fun, t):
        pass

    def mainloop(self):
        pass

    def setup(self, width=0.5, height=0.75, startx=None, starty=None):
        if isinstance(width, float) and 0 <= width <= 1:
            width = 1920 * width
        if isinstance(height, float) and 0 <= height <= 1:
            height = 1080 * height
        self.cv.config(width=int(width), height=int(height))
        self.canvwidth = int(width)
        self.canvheight = int(height)

    def title(self, titlestring):
        pass

    def bye(self):
        pass

    def exitonclick(self):
        pass

    def window_width(self):
        return self.cv.winfo_width()

    def window_height(self):
        return self.cv.winfo_height()

    def textinput(self, title, prompt):
        return None

    def numinput(self, title, prompt, default=None, minval=None, maxval=None):
        return None

def _oj_text_font(font):
    from PIL import ImageFont
    size = 8
    if isinstance(font, (tuple, list)) and len(font) > 1:
        try:
            size = int(font[1])
        except (TypeError, ValueError):
            size = 8
    pixels = -size if size < 0 else int(round(size * 96 / 72))
    try:
        return ImageFont.load_default(max(pixels, 1))
    except TypeError:
        return ImageFont.load_default()

def _oj_text_size(text, font):
    from PIL import Image, ImageDraw
    probe = ImageDraw.Draw(Image.new("RGB", (1, 1)))
    box = probe.textbbox((0, 0), str(text), font=_oj_text_font(font))
    return (box[2] - box[0], box[3] - box[1])

def _oj_render(canvas):
    from PIL import Image, ImageDraw
    width, height = canvas._size
    image = Image.new("RGB", (width, height), _oj_rgb(canvas._bg))
    draw = ImageDraw.Draw(image)
    center_x = width / 2.0
    center_y = height / 2.0
    for item in canvas._order:
        entry = canvas._items[item]
        options = entry["options"]
        values = entry["coords"]
        points = [(values[i] + center_x, values[i + 1] + center_y) for i in range(0, len(values) - 1, 2)]
        kind = entry["kind"]
        if kind == "polygon" and len(points) >= 3:
            fill = options.get("fill")
            outline = options.get("outline")
            if fill:
                draw.polygon(points, fill=_oj_rgb(fill))
            if outline:
                line_width = max(1, int(round(float(options.get("width", 1) or 1))))
                draw.line(points + [points[0]], fill=_oj_rgb(outline), width=line_width, joint="curve")
        elif kind == "line" and len(points) >= 2:
            fill = options.get("fill")
            if fill:
                line_width = max(1, int(round(float(options.get("width", 1) or 1))))
                color = _oj_rgb(fill)
                draw.line(points, fill=color, width=line_width, joint="curve")
                if line_width > 2:
                    radius = line_width / 2.0
                    for px, py in (points[0], points[-1]):
                        draw.ellipse((px - radius, py - radius, px + radius, py + radius), fill=color)
        elif kind == "text" and points:
            fill = options.get("fill") or "black"
            text = str(options.get("text", ""))
            left, top, right, bottom = canvas.bbox(item)
            font = _oj_text_font(options.get("font"))
            box = draw.textbbox((0, 0), text, font=font)
            draw.text((left + center_x - box[0], top + center_y - box[1]), text, fill=_oj_rgb(fill), font=font)
    return image

_oj_turtle.Turtle._screen = _OJScreen(_OJCanvas(800, 600))
