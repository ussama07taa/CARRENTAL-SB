'use client';

import {useEffect, useRef, useState} from 'react';
import {createPortal} from 'react-dom';
import '../clients/document-photo-input.css';

export default function InspectionVideoCamera({onVideo, onClose, onChoose}: {
  onVideo: (file: File) => void; onClose: () => void; onChoose: () => void;
}) {
  const dialog = useRef<HTMLDialogElement>(null), video = useRef<HTMLVideoElement>(null);
  const stream = useRef<MediaStream|null>(null), recorder = useRef<MediaRecorder|null>(null);
  const alive = useRef(false), timer = useRef<ReturnType<typeof setInterval>|null>(null);
  const [ready, setReady] = useState(false), [recording, setRecording] = useState(false);
  const [finishing, setFinishing] = useState(false), [seconds, setSeconds] = useState(0);
  const [error, setError] = useState(''), [facing, setFacing] = useState<'environment'|'user'>('environment');
  const stopTracks = () => {stream.current?.getTracks().forEach(track => track.stop()); stream.current = null;};
  const stopTimer = () => {if (timer.current) clearInterval(timer.current); timer.current = null;};
  const close = () => {alive.current = false; stopTimer(); if (recorder.current?.state === 'recording') recorder.current.stop(); stopTracks(); onClose();};
  useEffect(() => {
    alive.current = true; dialog.current?.showModal();
    return () => {alive.current = false; stopTimer(); if (recorder.current?.state === 'recording') recorder.current.stop(); stopTracks();};
  }, []);
  useEffect(() => {
    let cancelled = false; setReady(false); setError(''); stopTracks();
    void (async () => {
      if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
        setError('La vidéo en direct nécessite HTTPS et un navigateur compatible. Utilisez « Caméra vidéo du téléphone / fichier ».'); return;
      }
      try {
        const media = await navigator.mediaDevices.getUserMedia({audio: false, video: {facingMode: {ideal: facing}, width: {ideal: 1280}, height: {ideal: 720}}});
        if (cancelled || !alive.current) {media.getTracks().forEach(track => track.stop()); return;}
        stream.current = media;
        if (video.current) {video.current.srcObject = media; await video.current.play();}
        if (!cancelled && alive.current) setReady(true);
      } catch {if (!cancelled && alive.current) {stopTracks(); setError('Caméra indisponible. Autorisez son accès ou utilisez la caméra vidéo du téléphone / un fichier.');}}
    })();
    return () => {cancelled = true; stopTracks();};
  }, [facing]);
  function start() {
    if (!stream.current || !ready || recorder.current?.state === 'recording') return;
    try {
      const mime = ['video/mp4', 'video/webm;codecs=vp8', 'video/webm'].find(type => MediaRecorder.isTypeSupported(type));
      if (!mime) {setError('Enregistrement incompatible. Utilisez la caméra vidéo du téléphone / un fichier.'); return;}
      const capture = new MediaRecorder(stream.current, {mimeType: mime, videoBitsPerSecond: 1500000});
      recorder.current = capture; const chunks: Blob[] = []; let size = 0, elapsed = 0, failed = false;
      capture.ondataavailable = event => {if (event.data.size) {chunks.push(event.data); size += event.data.size;} if (size > 20*1024*1024 && capture.state === 'recording') capture.stop();};
      capture.onerror = () => {failed = true; stopTimer(); stopTracks(); if (alive.current) {setRecording(false); setFinishing(false); setReady(false); setError('Enregistrement interrompu. Fermez la caméra et réessayez.');}};
      capture.onstop = () => {
        stopTimer(); if (!alive.current || failed) return;
        const blob = new Blob(chunks, {type: capture.mimeType.split(';')[0]});
        if (!blob.size || blob.size > 20*1024*1024) {setRecording(false); setFinishing(false); setError('Vidéo vide ou supérieure à 20 Mo. Filmez une séquence plus courte.'); return;}
        onVideo(new File([blob], `inspection-${Date.now()}.${blob.type === 'video/mp4' ? 'mp4' : 'webm'}`, {type: blob.type})); close();
      };
      setError(''); setSeconds(0); setRecording(true); capture.start(500);
      timer.current = setInterval(() => {elapsed++; setSeconds(elapsed); if (elapsed >= 60 && capture.state === 'recording') {setFinishing(true); capture.stop();}}, 1000);
    } catch {setRecording(false); setError('Enregistrement indisponible. Utilisez la caméra vidéo du téléphone / un fichier.');}
  }
  return createPortal(<dialog ref={dialog} className="document-camera" aria-label="Vidéo du contrôle" onCancel={event => {event.preventDefault(); event.stopPropagation(); close();}}>
    <header><strong>Filmer le contrôle</strong><button type="button" onClick={close}>Annuler et fermer</button></header>
    <p>Filmez l’état de la voiture. Séquence de 60 secondes maximum, sans son. Vérifiez la vidéo avant de sauvegarder l’inspection.</p>
    <video ref={video} autoPlay muted playsInline aria-label="Vue de la caméra vidéo" />
    {!ready && !error && <p role="status">Ouverture de la caméra…</p>}
    {recording && <p role="status">{finishing ? 'Préparation de l’aperçu…' : `Enregistrement en cours · ${seconds} / 60 s`}</p>}
    {error && <p role="alert">{error}</p>}
    <footer>{recording ? <button type="button" disabled={finishing} onClick={() => {setFinishing(true); recorder.current?.stop();}}>Terminer et prévisualiser</button> : <><button type="button" disabled={!ready} onClick={start}>Démarrer la vidéo</button><button type="button" disabled={!ready} onClick={() => setFacing(value => value === 'user' ? 'environment' : 'user')}>Changer de caméra</button></>}
      <button type="button" disabled={recording} onClick={() => {close(); onChoose();}}>Caméra vidéo du téléphone / fichier</button>
    </footer>
  </dialog>, document.body);
}
