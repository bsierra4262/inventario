<?php
/**
 * Pie de página HTML reutilizable — INTELLIHELP
 */
?>
<script>
  // Cerrar sidebar mobile al hacer click en links
  document.querySelectorAll('.ih-app__sidebar a').forEach(function(a){
    a.addEventListener('click', function(){
      document.querySelector('.ih-app')?.classList.remove('is-open');
    });
  });
</script>
</body>
</html>
