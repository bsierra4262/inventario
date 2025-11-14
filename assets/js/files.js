document.addEventListener('DOMContentLoaded', function() {
    fetch('backend/files_fetch.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Error en la respuesta del servidor');
            }
            return response.json();
        })
        .then(data => {
            const tableBody = document.getElementById('files-table-body');
            tableBody.innerHTML = ''; // Limpiar contenido existente antes de agregar nuevos archivos
            data.forEach(file => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${file.product_id}</td>
                    <td>
                        <a href="uploads/${file.archivo}" target="_blank" class="btn btn-sm btn-primary">Ver</a>
                        <a href="uploads/${file.archivo}" download class="btn btn-sm btn-success">Descargar</a>
                    </td>
                    <td>${file.fecha}</td>
                `;
                tableBody.appendChild(row);
            });
        })
        .catch(error => {
            console.error('Hubo un problema con la petición fetch:', error);
        });

    document.getElementById('filter-file-id').addEventListener('input', applyFileFilters);
    document.getElementById('filter-file-date').addEventListener('input', applyFileFilters);
});

function applyFileFilters() {
    const filterFileId = document.getElementById('filter-file-id').value.toLowerCase();
    const filterUploadDate = document.getElementById('filter-file-date').value;
    const tableBody = document.getElementById('files-table-body');
    const rows = tableBody.getElementsByTagName('tr');

    Array.from(rows).forEach(row => {
        const fileId = row.cells[0].textContent.toLowerCase();
        const uploadDate = row.cells[2].textContent;

        if ((fileId.includes(filterFileId) || !filterFileId) &&
            (uploadDate.includes(filterUploadDate) || !filterUploadDate)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
