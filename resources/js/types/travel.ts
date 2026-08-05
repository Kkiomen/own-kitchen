/**
 * What the deals API answers with, after `App\Travel\TravelBoard` has renamed it
 * and turned every price into integer grosze.
 *
 * Codes and numbers only — no display copy. What a `round_trip` is *called* is
 * this app's decision and lives in `lib/travel.ts`.
 */

export type DealType = 'flight' | 'round_trip' | 'trip';

export interface Airport {
    code: string;
    /** Falls back to the code when the airport is unnamed. */
    city: string;
    country: string;
}

export interface AirportOption {
    code: string;
    label: string;
}

/**
 * One term an article names. Several of these are **alternatives**, not one long
 * stay: "4 lipca" *or* "12-15 września".
 */
export interface DealDates {
    from: string | null;
    to: string | null;
    /** The article's own Polish wording. */
    label: string;
}

export interface Deal {
    /**
     * A fingerprint, not an identity: it changes when the price does. Fine as a
     * `:key`, never as something to store and come back to.
     */
    id: string;
    source: string;
    type: DealType;
    title: string;
    /** Grosze, and what the **whole** offer costs — both legs, or the package. */
    price: number;
    currency: string;
    url: string;
    origin: Airport | null;
    destination: Airport | null;
    /** ISO 8601 instants. Convert them — never slice the first ten characters. */
    departsAt: string | null;
    returnsAt: string | null;
    /** Blog offers only, and not a departure: a past one means nothing. */
    publishedAt: string | null;
    weekend: boolean;
    steal: boolean;
    /** Grosze. The median total for this route, once enough of it was priced. */
    typicalPrice: number | null;
    /** Percent below `typicalPrice`. Only worth showing with it. */
    discount: number | null;
    /** Nights. Null often enough that "? nocy" must never be rendered. */
    days: number | null;
    board: string | null;
    hotelStars: number | null;
    tripDestination: string | null;
    hotel: string | null;
    departureCities: string[];
    dates: DealDates[];
    highlights: string[];
    hasDetails: boolean;
    /** 0-100. Flights rated on what they cost, trips on cost per day. */
    score: number | null;
    /** Grosze. */
    pricePerDay: number | null;
}

export interface TravelFilters {
    sort: string | null;
    type: string | null;
    weekends: boolean;
    steals: boolean;
    origin: string | null;
    destination: string | null;
    from: string | null;
    to: string | null;
}

export interface TravelMeta {
    sorts: string[];
    types: string[];
    boards: string[];
    /** The home airport. Listed first whatever the sort. */
    preferredOrigin: string | null;
    /** Nothing is collected further ahead than this. */
    windowDays: number | null;
}

export interface DealTotals {
    count: number;
    /** Grosze, or null when there is nothing of this kind. */
    cheapest: number | null;
}
