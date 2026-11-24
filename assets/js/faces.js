/* Shared face helpers (admin indexing + client "find my photos").
 * Everything runs in the browser; only 128-number face descriptors are sent to the server. */
window.SPFace = (() => {
  'use strict';
  const LIB = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/dist/face-api.js';
  const MODELS = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/model/';
  let ready = null;

  const load = () => ready || (ready = new Promise((resolve, reject) => {
    if (window.faceapi) { resolve(); return; }
    const s = document.createElement('script');
    s.src = LIB;
    s.async = true;
    s.onload = resolve;
    s.onerror = () => reject(new Error('โหลดตัวจดจำใบหน้าไม่สำเร็จ ตรวจสอบอินเทอร์เน็ตแล้วลองใหม่'));
    document.head.appendChild(s);
  }).then(async () => {
    const f = window.faceapi;
    if (f.tf && f.tf.ready) await f.tf.ready();
    await Promise.all([
      f.nets.tinyFaceDetector.loadFromUri(MODELS),
      f.nets.faceLandmark68Net.loadFromUri(MODELS),
      f.nets.faceRecognitionNet.loadFromUri(MODELS),
    ]);
  }).catch((e) => { ready = null; throw e; }));

  const opts = (size) => new window.faceapi.TinyFaceDetectorOptions({ inputSize: size, scoreThreshold: 0.45 });

  /** Every face in a photo → array of Float32Array(128). */
  const all = async (el) => (await window.faceapi.detectAllFaces(el, opts(512)).withFaceLandmarks().withFaceDescriptors())
    .map((d) => d.descriptor);

  /** The largest face in a selfie → Float32Array(128) or null. */
  const one = async (el) => {
    const list = await window.faceapi.detectAllFaces(el, opts(416)).withFaceLandmarks().withFaceDescriptors();
    if (!list.length) return null;
    list.sort((a, b) => (b.detection.box.width * b.detection.box.height) - (a.detection.box.width * a.detection.box.height));
    return list[0].descriptor;
  };

  const pack = (f32) => {
    const bytes = new Uint8Array(Float32Array.from(f32).buffer);
    let s = '';
    for (let i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
    return btoa(s);
  };

  const loadImg = (src) => new Promise((resolve, reject) => {
    const i = new Image();
    i.onload = () => resolve(i);
    i.onerror = () => reject(new Error('โหลดภาพไม่สำเร็จ'));
    i.src = src;
  });

  return { load, all, one, pack, loadImg };
})();
