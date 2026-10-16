import { useEffect, useRef } from 'react';
import * as THREE from 'three';

/**
 * Gold and violet dust that drifts behind the whole public site. It is one fixed canvas: the particles rise
 * slowly, the camera follows the pointer a little, and scrolling moves the field so the page feels deep.
 * Pauses in a hidden tab and frees everything on unmount.
 */
export default function DustField({ count = 520 }: { count?: number }) {
    const mount = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const host = mount.current;
        if (!host) return;

        let renderer: THREE.WebGLRenderer;
        try {
            renderer = new THREE.WebGLRenderer({ alpha: true, antialias: false, powerPreference: 'low-power' });
        } catch {
            return;
        }
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
        renderer.setClearColor(0x000000, 0);
        host.appendChild(renderer.domElement);
        renderer.domElement.style.cssText = 'width:100%;height:100%;display:block';

        const scene = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera(55, 1, 0.1, 100);
        camera.position.z = 14;

        // a soft round sprite drawn once on a canvas
        const c = document.createElement('canvas');
        c.width = c.height = 64;
        const g = c.getContext('2d')!;
        const grad = g.createRadialGradient(32, 32, 0, 32, 32, 32);
        grad.addColorStop(0, 'rgba(255,255,255,1)');
        grad.addColorStop(0.35, 'rgba(255,255,255,.55)');
        grad.addColorStop(1, 'rgba(255,255,255,0)');
        g.fillStyle = grad;
        g.fillRect(0, 0, 64, 64);
        const sprite = new THREE.CanvasTexture(c);

        const positions = new Float32Array(count * 3);
        const colors = new Float32Array(count * 3);
        const speeds = new Float32Array(count);
        const palette = [new THREE.Color('#c9a24b'), new THREE.Color('#e8c97a'), new THREE.Color('#fff3d6'), new THREE.Color('#b8864b')];
        for (let i = 0; i < count; i++) {
            positions.set([(Math.random() - 0.5) * 40, (Math.random() - 0.5) * 30, (Math.random() - 0.5) * 18], i * 3);
            const col = palette[Math.floor(Math.random() * (Math.random() < 0.7 ? 2 : palette.length))];
            colors.set([col.r, col.g, col.b], i * 3);
            speeds[i] = 0.15 + Math.random() * 0.35;
        }
        const geo = new THREE.BufferGeometry();
        geo.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        geo.setAttribute('color', new THREE.BufferAttribute(colors, 3));
        const mat = new THREE.PointsMaterial({
            size: 0.34,
            map: sprite,
            vertexColors: true,
            transparent: true,
            opacity: 0.55,
            depthWrite: false,
            sizeAttenuation: true,
        });
        const points = new THREE.Points(geo, mat);
        scene.add(points);

        const pointer = { x: 0, y: 0 };
        const onMove = (e: PointerEvent) => {
            pointer.x = (e.clientX / window.innerWidth) * 2 - 1;
            pointer.y = (e.clientY / window.innerHeight) * 2 - 1;
        };
        window.addEventListener('pointermove', onMove, { passive: true });

        const resize = () => {
            const w = window.innerWidth;
            const h = window.innerHeight;
            renderer.setSize(w, h, false);
            camera.aspect = w / h;
            camera.updateProjectionMatrix();
        };
        resize();
        window.addEventListener('resize', resize);

        let raf = 0;
        let last = performance.now();
        const tick = (now: number) => {
            const dt = Math.min(0.05, (now - last) / 1000);
            last = now;
            const pos = geo.getAttribute('position') as THREE.BufferAttribute;
            for (let i = 0; i < count; i++) {
                let y = pos.getY(i) + speeds[i] * dt;
                if (y > 15) y = -15;
                pos.setY(i, y);
            }
            pos.needsUpdate = true;
            // scrolling slides the whole field, the pointer tilts the camera
            const targetY = window.scrollY * 0.004;
            camera.position.y += (-targetY - camera.position.y) * 0.06;
            camera.position.x += (pointer.x * 1.2 - camera.position.x) * 0.04;
            camera.lookAt(camera.position.x * 0.3, camera.position.y, 0);
            points.rotation.z = Math.sin(now / 9000) * 0.05;
            renderer.render(scene, camera);
            raf = requestAnimationFrame(tick);
        };
        const start = () => {
            if (!raf) raf = requestAnimationFrame(tick);
        };
        const stop = () => {
            cancelAnimationFrame(raf);
            raf = 0;
        };
        const onVisibility = () => (document.hidden ? stop() : start());
        document.addEventListener('visibilitychange', onVisibility);
        start();

        return () => {
            stop();
            document.removeEventListener('visibilitychange', onVisibility);
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('resize', resize);
            geo.dispose();
            mat.dispose();
            sprite.dispose();
            renderer.dispose();
            renderer.forceContextLoss();
            renderer.domElement.remove();
        };
    }, [count]);

    return <div ref={mount} aria-hidden className="pointer-events-none fixed inset-0 -z-10" />;
}
