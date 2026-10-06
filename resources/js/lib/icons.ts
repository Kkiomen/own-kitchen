/**
 * One 24-unit grid and one stroke weight, so a row of these reads as a set
 * rather than as clip art collected from three places. Drawn rather than typed:
 * emoji render differently on every platform and cannot take the ink colour.
 */
export const ICON_PATHS = {
    search: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM16 16l4 4',
    clear: 'M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16zM9.5 9.5l5 5M14.5 9.5l-5 5',
    /** A domed plate: portions. */
    servings: 'M3 18h18M5 18a7 7 0 0 1 14 0M12 8V5M10 5h4',
    /** Numbered lines: an ordered procedure. */
    steps: 'M4 6h.01M4 12h.01M4 18h.01M9 6h11M9 12h11M9 18h7',
    /** A basket: the things you need to have. */
    ingredients:
        'M3 8h18l-1.7 10.2a2 2 0 0 1-2 1.8H6.7a2 2 0 0 1-2-1.8zM8 8l2-4M16 8l-2-4',
    clock: 'M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16zM12 8v4.5l3 1.8',
    box: 'M4 8l8-4 8 4v8l-8 4-8-4zM4 8l8 4 8-4M12 12v8',
    flag: 'M6 21V4M6 4h11l-2 3.5L17 11H6',
    source: 'M14 4h6v6M20 4l-8 8M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5',
    chevronLeft: 'M15 18l-6-6 6-6',
    qr: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 14h2M20 16v2M14 18v2h2M18 20h2',
    signOut: 'M15 17l5-5-5-5M20 12H9M12 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h6',
    plus: 'M12 5v14M5 12h14',
    /** The same bar as `plus`, so a stepper's two halves match. */
    minus: 'M5 12h14',
    /**
     * Timer controls. Triangle and bars are drawn on the same 24 grid as the
     * rest and stroked, not filled — the set has one weight and no exceptions.
     */
    play: 'M8 5.5l11 6.5-11 6.5z',
    pause: 'M9.5 5v14M14.5 5v14',
    /** A pot lid with a handle: the step is a wait, not a thing to do. */
    timer: 'M12 7a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM12 11v3.2l2.2 1.3M9.5 3h5M12 3v4',
    chevronRight: 'M9 6l6 6-6 6',
    /** A pencil: this row can be corrected. */
    pencil: 'M4 20h4L18.6 9.4a2 2 0 0 0-2.8-2.8L5 17.2V20zM14.8 7.6l2.8 2.8',
    /** A trolley: the list of what still has to be bought. */
    cart: 'M3 5h2.2l2.3 10.2a1.5 1.5 0 0 0 1.5 1.2h7.6a1.5 1.5 0 0 0 1.5-1.2L20 8H6M10 20h.01M17 20h.01',
    check: 'M5 12.5l4.5 4.5L19 7',
    /** The counterpart to `check`, on the same grid: this one you do not have. */
    cross: 'M6 6l12 12M18 6L6 18',
    /**
     * A measure divided down the middle: some of it, but not enough. Drawn as a
     * line rather than a filled half — the set is stroked, `fill` is none.
     */
    partial: 'M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16zM12 4v16',
    /** A price tag: this line is on offer. */
    tag: 'M11 3H4a1 1 0 0 0-1 1v7l9 9 8-8-9-9zM7.5 7.5h.01',
    /** A shopfront with an awning: one stop on the plan. */
    store: 'M4 9h16M4 9l1.5-4h13L20 9M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M9.5 20v-6h5v6',
    /** A month grid: the week being planned. */
    calendar:
        'M4 6a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1zM4 10h16M8 3v4M16 3v4',
    /** Two crossing arrows: give me a different one. */
    shuffle:
        'M4 7h3.5l9 10H20M4 17h3.5l9-10H20M17.5 4.5L20 7l-2.5 2.5M17.5 14.5L20 17l-2.5 2.5',
    /** A ticked list: what we asked each other to do. */
    checklist:
        'M4 6.5l1.6 1.6L8.8 5M4 17.5l1.6 1.6L8.8 16M12 7h8M12 12h8M12 17h8',
    /** An aeroplane seen from above: the trip, not the kitchen. */
    plane: 'M10.5 3.5a1.5 1.5 0 0 1 3 0V9l7 4v2l-7-2v4l2.5 2v1.5L12 19l-4-.5V17l2.5-2v-4l-7 2v-2l7-4z',
    /** A pin on a map: where it goes. */
    pin: 'M12 21s6.5-6 6.5-10.5a6.5 6.5 0 1 0-13 0C5.5 15 12 21 12 21zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
    /** A camera body with a lens: photograph the shelf instead of typing it. */
    camera: 'M4 8h3l1.5-2h7L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1zM12 16.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z',
    /** An empty plate: nothing matched. */
    empty: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM8 15c1-1.2 2.3-1.8 4-1.8s3 .6 4 1.8M9 9.5h.01M15 9.5h.01',
    /** A bell: this phone gets told. */
    bell: 'M12 3a5.5 5.5 0 0 0-5.5 5.5c0 5-2 6.5-2 6.5h15s-2-1.5-2-6.5A5.5 5.5 0 0 0 12 3zM10.3 18.5a2 2 0 0 0 3.4 0',
    /** The same bell, struck through: it does not. */
    bellOff:
        'M12 3a5.5 5.5 0 0 0-5.5 5.5c0 5-2 6.5-2 6.5h15s-2-1.5-2-6.5A5.5 5.5 0 0 0 12 3zM10.3 18.5a2 2 0 0 0 3.4 0M4 4l16 16',
    /** A heart: lubimy to — plan it again. */
    heart: 'M12 20s-7.5-4.6-7.5-10.2A4.3 4.3 0 0 1 12 7.2a4.3 4.3 0 0 1 7.5 2.6C19.5 15.4 12 20 12 20z',
    /** A circle struck through: never suggest this one again. */
    ban: 'M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16zM6.4 6.4l11.2 11.2',
} as const;

export type IconName = keyof typeof ICON_PATHS;
