/**
 * The five places the app actually has. Every screen belongs to one of them —
 * a recipe, the week, a shelf, the trolley or what we asked each other to do —
 * so the same destinations are on screen everywhere and none of them is ever
 * more than one tap away.
 *
 * Four was written here as the ceiling; the fifth was added and then measured
 * rather than argued about. At 390px each cell is 78px and the widest label,
 * "Zadania", renders at 50px — 28px of room over. A sixth would leave 15px,
 * which is where it stops working, so measure again before adding one.
 */
export type NavSection = 'recipes' | 'plan' | 'tasks' | 'pantry' | 'shopping';
