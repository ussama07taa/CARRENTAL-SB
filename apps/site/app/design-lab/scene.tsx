'use client';
import {Canvas} from '@react-three/fiber';
export default function Scene(){return <div className="bs-scene" role="img" aria-label="Étude de volume abstraite en 3D"><Canvas frameloop="demand" dpr={[1,1.5]} camera={{position:[0,0,5]}} fallback={<p>Aperçu statique : 3D indisponible.</p>}><ambientLight intensity={1.8}/><directionalLight position={[3,5,4]} intensity={3}/><mesh rotation={[.4,.6,.2]}><torusGeometry args={[1,.16,16,64]}/><meshStandardMaterial color="#004fff" roughness={.22} metalness={.6}/></mesh></Canvas></div>}
