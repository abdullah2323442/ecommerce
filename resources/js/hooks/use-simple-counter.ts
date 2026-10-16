import { useEffect, useState } from 'react';

const KEY = 'counter.simple';

/**
 * The simple screen for staff (spec 6.10): only search, quantity and save, everything else out of sight. It is a choice made on
 * this device and remembered, so a busy counter can stay simple and a desk can keep the full screen.
 */
export function useSimpleCounter(): [boolean, (on: boolean) => void] {
    const [simple, setSimple] = useState(false);

    useEffect(() => {
        try {
            setSimple(window.localStorage.getItem(KEY) === '1');
        } catch {
            /* storage is blocked: the full screen stays */
        }
    }, []);

    const choose = (on: boolean) => {
        setSimple(on);
        try {
            window.localStorage.setItem(KEY, on ? '1' : '0');
        } catch {
            /* the choice only lasts for this visit */
        }
    };

    return [simple, choose];
}
