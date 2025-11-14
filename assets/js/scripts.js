document.addEventListener('DOMContentLoaded', function() {
    fetch('backend/fetch.php')
        .then(response => response.json())
        .then(data => {
            const tableBody = document.getElementById('productos-table-body');
            tableBody.innerHTML = '';
            data.forEach(producto => {
                console.log(producto);
                console.log(producto.archivos);
                // Aseguramos que archivos sea un array para evitar errores
                const archivos = Array.isArray(producto.archivos) ? producto.archivos : [];
                const archivosHTML = archivos.map(file => `
                    <div class="btn-group">
                        <a href="uploads/${file}" target="_blank" class="btn btn-sm btn-primary">Ver</a>
                        <a href="uploads/${file}" download class="btn btn-sm btn-success">Descargar</a>
                    </div>
                `).join(" ");
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${producto.id}</td>
                    <td>${producto.modelo}</td>
                    <td>${producto.marca}</td>
                    <td>${producto.descripcion}</td>
                    <td>${producto.tipo}</td>
                    <td>${producto.donde_guardado}</td>
                    <td>${producto.serial}</td>
                    <td>${producto.precio}</td>
                    <td>${producto.moneda}</td>
                    <td>${producto.estado}</td>
                    <td>${archivosHTML}</td>
                    <td>${producto.fecha_modificacion}</td>
                    <td>${producto.fecha_ingreso}</td>
                    <td>${producto.fecha_egreso}</td>
                    <td>
                        <div class="btn-group">
                            <a href="update.html?id=${producto.id}" class="btn btn-warning btn-sm">Editar</a>
                            <button onclick="deleteProducto(${producto.id})" class="btn btn-danger btn-sm">Eliminar</button>
                        </div>
                    </td>
                `;
                tableBody.appendChild(row);
            });
        })
        .catch(error => {
            console.error('Error fetching data:', error);
        });

    document.getElementById('filter-id').addEventListener('input', applyFilters);
    document.getElementById('filter-name').addEventListener('input', applyFilters);
    document.getElementById('filter-price').addEventListener('change', applyFilters);
    document.getElementById('filter-serial').addEventListener('input', applyFilters);
    document.getElementById('filter-storage').addEventListener('input', applyFilters);
    document.getElementById('filter-status').addEventListener('change', applyFilters);
});

function applyFilters() {
    const filterId = document.getElementById('filter-id').value.toLowerCase();
    const filterName = document.getElementById('filter-name').value.toLowerCase();
    const filterPrice = document.getElementById('filter-price').value;
    const filterSerial = document.getElementById('filter-serial').value.toLowerCase();
    const filterStorage = document.getElementById('filter-storage').value.toLowerCase();
    const filterStatus = document.getElementById('filter-status').value;
    const tableBody = document.getElementById('productos-table-body');
    const rows = tableBody.getElementsByTagName('tr');

    Array.from(rows).forEach(row => {
        const id = row.cells[0].textContent.toLowerCase();
        const name = row.cells[3].textContent.toLowerCase();
        const price = row.cells[7].textContent;  // Se corrigió el índice
        const serial = row.cells[6].textContent.toLowerCase();
        const storage = row.cells[5].textContent.toLowerCase();
        const status = row.cells[9].textContent;

        if ((id.includes(filterId) || !filterId) &&
            (name.includes(filterName) || !filterName) &&
            (price === filterPrice || !filterPrice) &&
            (serial.includes(filterSerial) || !filterSerial) &&
            (storage.includes(filterStorage) || !filterStorage) &&
            (status.includes(filterStatus) || !filterStatus)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function deleteProducto(id) {
    if (confirm('¿Está seguro de que desea eliminar este producto?')) {
        fetch('backend/delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `id=${id}`
        }).then(response => {
            if (response.ok) {
                alert('Producto eliminado correctamente.');
                window.location.reload();
            } else {
                alert('Error al eliminar el producto.');
            }
        });
    }
}
