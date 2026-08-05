/**
 * The six places the app actually has. Every screen belongs to one of them —
 * a recipe, the week, a shelf, the trolley, what we asked each other to do, or
 * where to fly — so the same destinations are on screen everywhere and none of
 * them is ever more than one tap away.
 *
 * Four was written here as the ceiling, then five was added and measured rather
 * than argued about. The sixth was measured the same way, in the browser at
 * 390px: each cell is now 65px, the widest label ("Zadania") renders at 49.6px
 * and nothing overflows — 15px of room over, with a 65×56 tap target.
 *
 * That is the last one that fits. A seventh leaves 55.7px per cell against a
 * 49.6px label, which is no room at all, so the next section is a change of
 * shape (a "więcej" sheet), not another cell.
 *
 * `tone` is which hue the active cell wears. Everything is the green accent
 * except Podróż, which reads another app's data — flights rather than food — and
 * says so in blue before its heading does. See the palette note in `app.css`;
 * this is the single deliberate exception, not a second palette.
 */
export type NavSection =
    'recipes' | 'plan' | 'tasks' | 'pantry' | 'shopping' | 'travel';

export type NavTone = 'accent' | 'voyage';
