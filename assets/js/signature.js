/*
 * Aláírás rögzítő (touch + egér + toll) Pointer Events alapon, retina kijelzőre optimalizálva.
 * Billentyűzetes felhasználóknak: a "Gépelt aláírás" mező a nevet rajzolja a vászonra.
 */
(function (global) {
  'use strict';

  class SignaturePad {
    constructor(canvas, onChange) {
      this.canvas = canvas;
      this.ctx = canvas.getContext('2d');
      this.onChange = onChange || function () {};
      this.drawing = false;
      this.strokes = 0;
      this.last = null;
      this.resize = this.resize.bind(this);
      this.resize();
      global.addEventListener('resize', this.resize);
      canvas.addEventListener('pointerdown', (e) => this.start(e));
      canvas.addEventListener('pointermove', (e) => this.move(e));
      ['pointerup', 'pointercancel', 'pointerleave'].forEach((t) => canvas.addEventListener(t, () => this.end()));
    }

    resize() {
      const data = this.strokes > 0 ? this.canvas.toDataURL() : null;
      const rect = this.canvas.getBoundingClientRect();
      const ratio = Math.max(global.devicePixelRatio || 1, 1);
      this.canvas.width = Math.max(300, Math.round(rect.width * ratio));
      this.canvas.height = Math.max(120, Math.round(rect.height * ratio));
      this.ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
      this.ctx.lineWidth = 2.4;
      this.ctx.lineCap = 'round';
      this.ctx.lineJoin = 'round';
      this.ctx.strokeStyle = '#0f172a';
      if (data) {
        const img = new Image();
        img.onload = () => this.ctx.drawImage(img, 0, 0, rect.width, rect.height);
        img.src = data;
      }
    }

    point(e) {
      const rect = this.canvas.getBoundingClientRect();
      return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    }

    start(e) {
      e.preventDefault();
      this.canvas.setPointerCapture && this.canvas.setPointerCapture(e.pointerId);
      this.drawing = true;
      this.last = this.point(e);
      this.ctx.beginPath();
      this.ctx.arc(this.last.x, this.last.y, 1.1, 0, Math.PI * 2);
      this.ctx.fillStyle = '#0f172a';
      this.ctx.fill();
    }

    move(e) {
      if (!this.drawing) return;
      e.preventDefault();
      const p = this.point(e);
      this.ctx.beginPath();
      this.ctx.moveTo(this.last.x, this.last.y);
      this.ctx.lineTo(p.x, p.y);
      this.ctx.stroke();
      this.last = p;
    }

    end() {
      if (!this.drawing) return;
      this.drawing = false;
      this.strokes++;
      this.onChange(this.isEmpty());
    }

    typeName(name) {
      this.clear();
      const rect = this.canvas.getBoundingClientRect();
      this.ctx.font = 'italic 38px "Brush Script MT", "Segoe Script", cursive';
      this.ctx.fillStyle = '#0f172a';
      this.ctx.textBaseline = 'middle';
      this.ctx.fillText(name, 20, rect.height / 2, rect.width - 40);
      this.strokes = name.trim() ? 1 : 0;
      this.onChange(this.isEmpty());
    }

    clear() {
      this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
      this.strokes = 0;
      this.onChange(true);
    }

    isEmpty() {
      return this.strokes === 0;
    }

    /* Fehér hátterű, CSS-pixel méretű PNG (kisebb fájl, szerver oldali limit alatt). */
    toPNG() {
      const rect = this.canvas.getBoundingClientRect();
      const out = document.createElement('canvas');
      out.width = Math.max(300, Math.round(rect.width));
      out.height = Math.max(120, Math.round(rect.height));
      const ctx = out.getContext('2d');
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, out.width, out.height);
      ctx.drawImage(this.canvas, 0, 0, out.width, out.height);
      return out.toDataURL('image/png');
    }
  }

  global.SignaturePad = SignaturePad;
})(window);
