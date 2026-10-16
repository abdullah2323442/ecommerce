/**
 * Layered water waves (the brand is "Aqua"). Each layer is a repeating curve that drifts sideways at its
 * own speed, so the layers slide past each other like real swell. The last layer takes the colour of the
 * section below, so the water flows into the page. Motion stops for reduced-motion users.
 */
const PERIOD = 720;
const PERIODS = 4; // 2880 wide: twice the visible 1440, so a 1440 drift loops seamlessly

function curve(amplitude: number, phase: number): string {
    let d = `M${-phase} 60`;
    for (let i = 0; i < PERIODS; i++) {
        const x = i * PERIOD - phase;
        d += ` C${x + 120} ${60 - amplitude},${x + 240} ${60 - amplitude},${x + 360} 60 S${x + 600} ${60 + amplitude},${x + PERIOD} 60`;
    }
    return `${d} V121 H${-phase} Z`;
}

const LAYERS = [
    { fill: 'var(--wave-1)', amplitude: 30, phase: 0, seconds: 34, reverse: false },
    { fill: 'var(--wave-2)', amplitude: 22, phase: 240, seconds: 26, reverse: true },
    { fill: 'var(--wave-3)', amplitude: 14, phase: 480, seconds: 19, reverse: false },
];

export default function Wave({ className = '' }: { className?: string }) {
    return (
        <svg viewBox="0 0 1440 120" preserveAspectRatio="none" aria-hidden className={`block h-16 w-full sm:h-24 md:h-28 ${className}`}>
            {LAYERS.map((layer, i) => (
                <g key={i} className="wave-layer" style={{ animationDuration: `${layer.seconds}s`, animationDirection: layer.reverse ? 'reverse' : 'normal' }}>
                    <path d={curve(layer.amplitude, layer.phase)} fill={layer.fill} />
                </g>
            ))}
        </svg>
    );
}

/** A single quiet wave for the top edge of a section. `fill` is the colour of the section it belongs to. */
export function WaveDivider({ fill, className = '' }: { fill: string; className?: string }) {
    return (
        <svg viewBox="0 0 1440 120" preserveAspectRatio="none" aria-hidden className={`block h-10 w-full sm:h-16 ${className}`}>
            <g className="wave-layer" style={{ animationDuration: '40s' }}>
                <path d={curve(18, 120)} fill={fill} />
            </g>
        </svg>
    );
}
