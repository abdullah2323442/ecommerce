import { Gamepad2, LifeBuoy, Ruler, Store, UtensilsCrossed, type LucideIcon } from 'lucide-react';

/**
 * The shops that are not built yet. One list feeds the department strip and the "coming soon" row, so
 * adding a sector means adding one line here. Restricted sections are never listed (rule G1).
 */
export interface Department {
    key: string;
    en: string;
    bn: string;
    blurb: string;
    Icon: LucideIcon;
}

export const SOON: Department[] = [
    { key: 'food', en: 'Food', bn: 'খাবার', blurb: 'Fast food from local restaurants', Icon: UtensilsCrossed },
    { key: 'games', en: 'Gaming', bn: 'গেমিং', blurb: 'Game top-ups and diamonds', Icon: Gamepad2 },
    { key: 'custom', en: 'Custom', bn: 'মাপমতো', blurb: 'Wood and clothing made to your size', Icon: Ruler },
    { key: 'market', en: 'The Market', bn: 'বাজার', blurb: 'Everyday things from many sellers', Icon: Store },
    { key: 'help', en: 'Help board', bn: 'সাহায্য', blurb: 'Find work, find a hand', Icon: LifeBuoy },
];
