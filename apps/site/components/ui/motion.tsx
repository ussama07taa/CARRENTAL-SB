'use client';
import {motion,MotionConfig,useReducedMotion} from 'motion/react';
import type {ReactNode} from 'react';
export function Reveal({children}:{children:ReactNode}){const reduced=useReducedMotion();return <MotionConfig reducedMotion="user"><motion.div initial={false} whileInView={{opacity:1,y:0}} viewport={{once:true}} transition={{duration:reduced?0:.45}}>{children}</motion.div></MotionConfig>}
export function Banner({children}:{children:ReactNode}){const reduced=useReducedMotion();return <MotionConfig reducedMotion="user"><motion.section className="bs-banner" initial={reduced?false:{opacity:0,y:16}} animate={{opacity:1,y:0}} transition={{duration:reduced?0:.55,ease:'easeOut'}}>{children}</motion.section></MotionConfig>}
