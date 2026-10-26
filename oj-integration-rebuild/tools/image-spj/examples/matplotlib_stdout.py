import sys

import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt

# Read ordinary text input, for example: 1 3 2 4
values = [float(value) for value in sys.stdin.read().split()]
figure, axes = plt.subplots(figsize=(6.4, 4.8), dpi=100)
axes.plot(range(len(values)), values, color="black", linewidth=2)
axes.set_ylim(0, 5)
figure.savefig(sys.stdout.buffer, format="png", facecolor="white", transparent=False)
sys.stdout.buffer.flush()
plt.close(figure)
