// Display edit form
const editEl = document.getElementById('edit-requete');

if (editEl)
	editEl.onclick = evt => {
		editEl.classList.add('edit-form');
	}

// Do not add empty parameters to the edit request
function submitEdited(evt) {
	document.querySelectorAll('#edit-requete input')
		.forEach(elInput => {
			if (!elInput.value)
				elInput.setAttribute('disabled', '');
		});
}