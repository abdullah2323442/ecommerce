import { Capsule3D, Tablet3D } from '@/components/art/pharma-3d';
import Wave from '@/components/art/wave';

/**
 * A decorative strip for the top of admin pages: a little water along the bottom and a few 3D pieces
 * resting on the right. It keeps the admin looking like the same product as the public street.
 */
export function Wave3D() {
    return (
        <>
            <div aria-hidden className="pointer-events-none absolute top-3 right-4 hidden w-40 sm:block">
                <Capsule3D color="#1f8a8a" colorB="#fbf7ee" className="float-b w-full drop-shadow-lg" flat />
                <Tablet3D className="float-c -mt-2 ml-14 w-14 drop-shadow-md" flat />
            </div>
            <div className="public-theme pointer-events-none absolute inset-x-0 bottom-0 [--pt-paper:var(--card)]" aria-hidden>
                <Wave className="!h-8 sm:!h-10" />
            </div>
        </>
    );
}
