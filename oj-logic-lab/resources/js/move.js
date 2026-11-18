import { GRID, canPlace } from './model.js?v=1.0.2';
import { routeCircuit } from './router.js?v=1.0.2';

// A drag preview is committed only when the node and every wire have a legal
// location. Rejected pointer positions never replace the last valid routing.
export function previewMove(project, id, x, y) {
    const original = project.nodes.find(n => n.id === id); if (!original) return null;
    const node = { ...original, x: Math.round(x / GRID) * GRID, y: Math.round(y / GRID) * GRID };
    if (!canPlace(project.nodes, node)) return null;
    const candidate = { ...project, nodes: project.nodes.map(n => n.id === id ? node : n) };
    const routing = routeCircuit(candidate); if (routing.failures.length) return null;
    return { node, routing };
}
