import { useEffect, useRef } from 'react';
import * as THREE from 'three';
import { RoomEnvironment } from 'three/examples/jsm/environments/RoomEnvironment.js';

/**
 * A slowly turning glass gem with a gold wire cage, lit by a studio environment. It leans toward the pointer.
 * Sized by its container; the canvas is transparent so it floats over the hero.
 */
export default function GemOrb() {
    const mount = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const host = mount.current;
        if (!host) return;

        let renderer: THREE.WebGLRenderer;
        try {
            renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true });
        } catch {
            return;
        }
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.setClearColor(0x000000, 0);
        renderer.toneMapping = THREE.ACESFilmicToneMapping;
        host.appendChild(renderer.domElement);
        renderer.domElement.style.cssText = 'width:100%;height:100%;display:block';

        const scene = new THREE.Scene();
        const pmrem = new THREE.PMREMGenerator(renderer);
        const env = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
        scene.environment = env;

        const camera = new THREE.PerspectiveCamera(40, 1, 0.1, 50);
        camera.position.z = 5.2;

        const group = new THREE.Group();
        scene.add(group);

        const gemGeo = new THREE.IcosahedronGeometry(1.35, 1);
        const glass = new THREE.MeshPhysicalMaterial({
            color: 0xfff1d0,
            metalness: 0.05,
            roughness: 0.04,
            transmission: 1,
            thickness: 1.4,
            ior: 1.55,
            iridescence: 0.7,
            iridescenceIOR: 1.4,
            clearcoat: 1,
            envMapIntensity: 1.6,
            flatShading: true,
        });
        group.add(new THREE.Mesh(gemGeo, glass));

        const wire = new THREE.LineSegments(
            new THREE.WireframeGeometry(new THREE.IcosahedronGeometry(1.62, 1)),
            new THREE.LineBasicMaterial({ color: 0xe8c97a, transparent: true, opacity: 0.7 }),
        );
        group.add(wire);

        const core = new THREE.Mesh(
            new THREE.OctahedronGeometry(0.42, 0),
            new THREE.MeshStandardMaterial({ color: 0xc9a24b, metalness: 1, roughness: 0.22, envMapIntensity: 1.4 }),
        );
        group.add(core);

        const key = new THREE.DirectionalLight(0xffe2a8, 1.6);
        key.position.set(3, 3, 4);
        scene.add(key);

        const pointer = { x: 0, y: 0 };
        const onMove = (e: PointerEvent) => {
            pointer.x = (e.clientX / window.innerWidth) * 2 - 1;
            pointer.y = (e.clientY / window.innerHeight) * 2 - 1;
        };
        window.addEventListener('pointermove', onMove, { passive: true });

        const resize = () => {
            const w = Math.max(1, host.clientWidth);
            const h = Math.max(1, host.clientHeight);
            renderer.setSize(w, h, false);
            camera.aspect = w / h;
            camera.updateProjectionMatrix();
        };
        resize();
        const ro = new ResizeObserver(resize);
        ro.observe(host);

        let raf = 0;
        let visible = true;
        const tick = (now: number) => {
            const t = now / 1000;
            group.rotation.y = t * 0.22 + pointer.x * 0.5;
            group.rotation.x = Math.sin(t * 0.4) * 0.15 + pointer.y * 0.35;
            wire.rotation.y = -t * 0.12;
            core.rotation.set(t * 0.7, t * 0.5, 0);
            group.position.y = Math.sin(t * 0.8) * 0.12;
            renderer.render(scene, camera);
            raf = requestAnimationFrame(tick);
        };
        const sync = () => {
            const run = visible && !document.hidden;
            if (run && !raf) raf = requestAnimationFrame(tick);
            if (!run && raf) {
                cancelAnimationFrame(raf);
                raf = 0;
            }
        };
        const io = new IntersectionObserver((e) => {
            visible = e[0].isIntersecting;
            sync();
        });
        io.observe(host);
        document.addEventListener('visibilitychange', sync);
        sync();

        return () => {
            if (raf) cancelAnimationFrame(raf);
            io.disconnect();
            ro.disconnect();
            document.removeEventListener('visibilitychange', sync);
            window.removeEventListener('pointermove', onMove);
            gemGeo.dispose();
            glass.dispose();
            env.dispose();
            pmrem.dispose();
            renderer.dispose();
            renderer.forceContextLoss();
            renderer.domElement.remove();
        };
    }, []);

    return <div ref={mount} aria-hidden className="pointer-events-none h-full w-full" />;
}
