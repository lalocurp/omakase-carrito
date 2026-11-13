/* OMAKASE - Carrito global (se incluye en TODAS las páginas) */
const Carrito = {
  KEY: 'omakase_carrito',

obtener() {
  try {
    const items = JSON.parse(localStorage.getItem(this.KEY)) || [];
    return items.filter(i => Number.isInteger(i.id) && i.id > 0 &&
                             typeof i.precio === 'number' && !isNaN(i.precio) &&
                             i.cantidad > 0);
  } catch (e) { return []; }
},

  guardar(items) {
    localStorage.setItem(this.KEY, JSON.stringify(items));
    this.actualizarContador();
  },

  agregar(producto) {
    const items = this.obtener();
    const existente = items.find(i => i.id === producto.id);
    if (existente) existente.cantidad += 1;
    else items.push({ ...producto, cantidad: 1 });
    this.guardar(items);
  },

  cambiarCantidad(id, delta) {
    let items = this.obtener();
    const it = items.find(i => i.id === id);
    if (!it) return;
    it.cantidad += delta;
    if (it.cantidad <= 0) items = items.filter(i => i.id !== id);
    this.guardar(items);
  },

  quitar(id) {
    this.guardar(this.obtener().filter(i => i.id !== id));
  },

  vaciar() { this.guardar([]); },

  total() {
    return this.obtener().reduce((s, i) => s + i.precio * i.cantidad, 0);
  },

  actualizarContador() {
    const n = this.obtener().reduce((s, i) => s + i.cantidad, 0);
    document.querySelectorAll('#cart-count').forEach(el => el.textContent = n);
  },

  aviso(texto) {
    let t = document.getElementById('toast-carrito');
    if (!t) {
      t = document.createElement('div');
      t.id = 'toast-carrito';
      document.body.appendChild(t);
    }
    t.textContent = texto;
    t.classList.add('visible');
    clearTimeout(this._timer);
    this._timer = setTimeout(() => t.classList.remove('visible'), 1800);
  }
};

document.addEventListener('DOMContentLoaded', () => {
  Carrito.actualizarContador();

  // Cualquier botón con la clase .btn-agregar agrega su producto
  document.addEventListener('click', e => {
    const btn = e.target.closest('.btn-agregar');
    if (!btn) return;
    Carrito.agregar({
      id: parseInt(btn.dataset.id, 10),
      nombre: btn.dataset.nombre,
      precio: parseFloat(btn.dataset.precio)
    });
    Carrito.aviso('✔ ' + btn.dataset.nombre + ' añadido al carrito');
  });
});
