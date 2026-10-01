{{-- Unterschrift (App\Filament\Forms\SignaturePad); Stil in public/css/vcontrol.css --}}
@php
    $statePath = $getStatePath();
    $disabled = $isDisabled();
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @if ($disabled)
        @if (\App\Filament\Forms\SignaturePad::isValid($getState()))
            <img src="{{ $getState() }}" alt="Unterschrift" class="vc-signature__img">
        @else
            <p class="vc-muted">Keine Unterschrift.</p>
        @endif
    @else
        <div
            class="vc-signature"
            wire:ignore
            x-data="{
                state: $wire.$entangle(@js($statePath)),
                drawing: false,
                ctx: null,
                observer: null,
                init() {
                    this.ctx = this.$refs.canvas.getContext('2d');
                    this.observer = new ResizeObserver(() => this.resize());
                    this.observer.observe(this.$refs.canvas);
                    this.resize();
                },
                {{-- Dialog zu oder Seitenwechsel: sonst meldet sich der Observer am entfernten Canvas --}}
                destroy() {
                    this.observer?.disconnect();
                },
                resize() {
                    const canvas = this.$refs.canvas;
                    if (!canvas) return;
                    const width = canvas.clientWidth;
                    if (width === 0) return;
                    const ratio = window.devicePixelRatio || 1;
                    canvas.width = width * ratio;
                    canvas.height = 180 * ratio;
                    this.ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                    this.blank();
                    if (this.state) {
                        const image = new Image();
                        image.onload = () => this.ctx.drawImage(image, 0, 0, width, 180);
                        image.src = this.state;
                    }
                },
                blank() {
                    this.ctx.fillStyle = '#fff';
                    this.ctx.fillRect(0, 0, this.$refs.canvas.clientWidth, 180);
                    this.ctx.lineWidth = 2.2;
                    this.ctx.lineCap = 'round';
                    this.ctx.lineJoin = 'round';
                    this.ctx.strokeStyle = '#111';
                },
                point(event) {
                    const box = this.$refs.canvas.getBoundingClientRect();
                    return [event.clientX - box.left, event.clientY - box.top];
                },
                start(event) {
                    this.drawing = true;
                    this.$refs.canvas.setPointerCapture(event.pointerId);
                    const [x, y] = this.point(event);
                    this.ctx.beginPath();
                    this.ctx.moveTo(x, y);
                    this.ctx.lineTo(x + 0.1, y + 0.1);
                    this.ctx.stroke();
                },
                move(event) {
                    if (!this.drawing) return;
                    const [x, y] = this.point(event);
                    this.ctx.lineTo(x, y);
                    this.ctx.stroke();
                },
                end() {
                    if (!this.drawing) return;
                    this.drawing = false;
                    this.state = this.$refs.canvas.toDataURL('image/png');
                },
                clear() {
                    this.blank();
                    this.state = null;
                },
            }"
        >
            <canvas
                x-ref="canvas"
                class="vc-signature__canvas"
                x-on:pointerdown.prevent="start($event)"
                x-on:pointermove.prevent="move($event)"
                x-on:pointerup="end()"
                x-on:pointercancel="end()"
            ></canvas>
            <div class="vc-signature__bar">
                <span class="vc-muted">Mit Finger, Stift oder Maus unterschreiben.</span>
                <button type="button" class="vc-signature__clear" x-on:click="clear()">Unterschrift löschen</button>
            </div>
        </div>
    @endif
</x-dynamic-component>
