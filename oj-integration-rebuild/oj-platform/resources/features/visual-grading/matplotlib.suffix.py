def _oj_emit_matplotlib_png():
    import io as _oj_io
    import matplotlib.pyplot as _oj_plt
    if not _oj_plt.get_fignums():
        return
    _oj_buffer = _oj_io.BytesIO()
    _oj_plt.gcf().savefig(_oj_buffer, format="png", dpi=100, facecolor="white", transparent=False)
    _oj_stdout.buffer.write(_oj_buffer.getvalue())
    _oj_stdout.buffer.flush()
_oj_emit_matplotlib_png()
