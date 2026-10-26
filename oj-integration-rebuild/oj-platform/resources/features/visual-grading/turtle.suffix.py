def _oj_emit_turtle_png():
    import io as _oj_io
    _oj_screen = _oj_turtle.Turtle._screen
    if _oj_screen is None:
        return
    _oj_screen.update()
    _oj_buffer = _oj_io.BytesIO()
    _oj_render(_oj_screen.cv).save(_oj_buffer, format="PNG")
    _oj_stdout.buffer.write(_oj_buffer.getvalue())
    _oj_stdout.buffer.flush()
_oj_emit_turtle_png()
