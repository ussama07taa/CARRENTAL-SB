'use client';

import {useEffect, useRef, useState} from 'react';

export default function DocumentCamera({label, onPhoto, onClose, onChoose, instruction}: {
  instruction?: string; label: string; onPhoto: (file: File) => void; onClose: () => void; onChoose: () => void;
}) {
  const dialog = useRef<HTMLDialogElement>(null);
  const video = useRef<HTMLVideoElement>(null);
  const stream = useRef<MediaStream | null>(null);
  const [facing, setFacing] = useState<'environment'|'user'>('environment');
  const [ready, setReady] = useState(false);
  const [error, setError] = useState('');
  const [taking, setTaking] = useState(false);
  const [retry, setRetry] = useState(0);
  const alive = useRef(true);
  const stop = () => {stream.current?.getTracks().forEach(track => track.stop()); stream.current = null;};
  const close = () => {alive.current = false; stop(); onClose();};

  useEffect(() => {
    alive.current = true;
    dialog.current?.showModal();
    return () => {alive.current = false; stop();};
  }, []);

  useEffect(() => {
    let cancelled = false;
    setReady(false); setError(''); stop();
    async function open() {
      if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
        setError('La caméra nécessite une connexion HTTPS (ou localhost sur cet appareil). Utilisez « Appareil photo du téléphone » ou choisissez une image.');
        return;
      }
      try {
        const media = await navigator.mediaDevices.getUserMedia({audio: false, video: {facingMode: {ideal: facing}, width: {ideal: 1920}, height: {ideal: 1080}}});
        if (cancelled || !alive.current) {media.getTracks().forEach(track => track.stop()); return;}
        stream.current = media;
        if (video.current) {video.current.srcObject = media; await video.current.play();}
        if (!cancelled && alive.current) setReady(true);
      } catch (e) {
        if (cancelled || !alive.current) return;
        stop();
        const name = e instanceof DOMException ? e.name : '';
        setError(name === 'NotAllowedError' || name === 'SecurityError'
          ? 'Accès à la caméra refusé. Autorisez la caméra dans les réglages du navigateur, puis réessayez.'
          : name === 'NotFoundError' || name === 'OverconstrainedError'
            ? 'Aucune caméra disponible. Branchez une webcam ou choisissez une image.'
            : 'La caméra est indisponible ou utilisée par une autre application. Fermez cette application, puis réessayez.');
      }
    }
    void open();
    return () => {cancelled = true; stop();};
  }, [facing, retry]);

  function capture() {
    const source = video.current;
    if (!source || !ready || taking || !source.videoWidth || !source.videoHeight) return;
    setTaking(true);
    const canvas = document.createElement('canvas');
    const ratio = Math.min(1, 2400 / Math.max(source.videoWidth, source.videoHeight));
    canvas.width = Math.round(source.videoWidth * ratio); canvas.height = Math.round(source.videoHeight * ratio);
    const context = canvas.getContext('2d');
    if (!context) {setTaking(false); setError('Photo indisponible. Réessayez.'); return;}
    context.drawImage(source, 0, 0, canvas.width, canvas.height);
    canvas.toBlob(blob => {
      if (!alive.current) return;
      if (!blob) {setTaking(false); setError('Photo indisponible. Réessayez.'); return;}
      onPhoto(new File([blob], 'document-' + Date.now() + '.jpg', {type: 'image/jpeg'}));
      close();
    }, 'image/jpeg', 0.9);
  }

  return <dialog ref={dialog} className="document-camera" aria-label={'Caméra : ' + label}
    onCancel={e => {e.preventDefault(); e.stopPropagation(); close();}}>
    <header><strong>{label}</strong><button type="button" onClick={close}>Fermer la caméra</button></header>
    <p>{instruction??'Placez le document dans le cadre, sans reflet. La photo sera à vérifier avant l’enregistrement.'}</p>
    <video ref={video} autoPlay muted playsInline aria-label="Vue de la caméra" />
    {!ready && !error && <p role="status">Ouverture de la caméra… Autorisez son utilisation si le navigateur le demande.</p>}
    {error && <p role="alert">{error}</p>}
    <footer>
      <button type="button" disabled={!ready || taking} onClick={capture}>{taking ? 'Capture…' : 'Capturer la photo'}</button>
      <button type="button" disabled={!ready || taking} onClick={() => setFacing(value => value === 'environment' ? 'user' : 'environment')}>Changer de caméra</button>
      {error && <button type="button" onClick={() => setRetry(value => value + 1)}>Réessayer la caméra</button>}
      <button type="button" onClick={() => {close(); onChoose();}}>Appareil photo du téléphone / fichier</button>
    </footer>
  </dialog>;
}
