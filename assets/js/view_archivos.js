document.addEventListener('DOMContentLoaded', function () {
    fetch('backend/fetch-uploads.php')
        .then(response => {
            if (!response.ok) {
                throw new Error(`Error en la respuesta del servidor: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            const tableBody = document.getElementById('files-table-body');
            tableBody.innerHTML = ''; // Limpiar contenido existente antes de agregar nuevos archivos
            data.forEach(file => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${file.name}</td>
                    <td>${file.upload_date}</td>
                    <td>
                        <a href="uploads/${file.name}" class="btn btn-primary btn-sm" target="_blank">Ver</a>
                        <a href="uploads/${file.name}" class="btn btn-success btn-sm" download>Descargar</a>
                    </td>
                `;
                tableBody.appendChild(row);
            });
        })
        .catch(error => {
            console.error('Hubo un problema con la petición fetch:', error);
            alert('No se pudieron cargar los archivos. Por favor, intente nuevamente más tarde.');
        });

    document.getElementById('filter-file-name').addEventListener('input', applyFileFilters);
    document.getElementById('filter-upload-date').addEventListener('change', applyFileFilters);
});

function applyFileFilters() {
    const filterFileName = document.getElementById('filter-file-name').value.toLowerCase();
    const filterUploadDate = document.getElementById('filter-upload-date').value;
    const tableBody = document.getElementById('files-table-body');
    const rows = tableBody.getElementsByTagName('tr');

    Array.from(rows).forEach(row => {
        const fileName = row.cells[0].textContent.toLowerCase();
        const uploadDate = row.cells[1].textContent;

        if ((fileName.includes(filterFileName) || !filterFileName) &&
            (uploadDate.includes(filterUploadDate) || !filterUploadDate)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
