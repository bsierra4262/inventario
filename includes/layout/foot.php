<?php
/**
 * Pie de página HTML reutilizable — INTELLIHELP
 */
?>
<script>
(function () {
  var app = document.querySelector('.ih-app');
  if (!app) return;

  // El fondo oscuro que tapa el contenido con el menu abierto. La hoja del panel
  // ya lo estilaba (.ih-backdrop), pero el elemento no existia en el markup.
  var fondo = app.querySelector('.ih-backdrop');
  if (!fondo) {
    fondo = document.createElement('div');
    fondo.className = 'ih-backdrop';
    app.appendChild(fondo);
  }

  function cerrar() {
    app.classList.remove('is-open');
    var b = document.querySelector('.ih-topbar__burger');
    if (b) b.setAttribute('aria-expanded', 'false');
  }

  // Lo llama el boton del topbar. Antes solo existia el codigo para CERRAR el
  // menu: no habia forma de abrirlo.
  window.ihAlternarMenu = function (boton) {
    var abierto = app.classList.toggle('is-open');
    if (boton) boton.setAttribute('aria-expanded', abierto ? 'true' : 'false');
  };

  fondo.addEventListener('click', cerrar);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrar(); });
  // Al elegir una opcion el menu se cierra solo.
  document.querySelectorAll('.ih-app__sidebar a').forEach(function (a) {
    a.addEventListener('click', cerrar);
  });
  // Si la pantalla se agranda, el menu deja de estar en modo cajon.
  window.addEventListener('resize', function () { if (window.innerWidth > 991) cerrar(); });
})();
</script>
</body>
</html>
