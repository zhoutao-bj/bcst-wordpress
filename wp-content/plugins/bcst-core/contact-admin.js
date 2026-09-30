(function () {
    'use strict';
    const list = document.getElementById('bcst-people');
    if (!list) return;
    let index = list.children.length;
    document.getElementById('bcst-person-add').addEventListener('click', function () {
        list.insertAdjacentHTML('beforeend', document.getElementById('bcst-person-template').innerHTML.replace(/__INDEX__/g, String(index++)));
    });
    list.addEventListener('click', function (event) {
        const button = event.target.closest('button');
        if (!button) return;
        const row = button.closest('.bcst-person');
        if (button.classList.contains('bcst-person-remove')) row.remove();
        if (button.classList.contains('bcst-person-clear')) {
            row.querySelector('.bcst-person-photo').value = '0';
            row.querySelector('.bcst-person-preview').replaceChildren();
        }
        if (button.classList.contains('bcst-person-upload')) {
            const frame = wp.media({title: '选择销售人员头像', library: {type: 'image'}, multiple: false});
            frame.on('select', function () {
                const photo = frame.state().get('selection').first().toJSON();
                row.querySelector('.bcst-person-photo').value = photo.id;
                const image = document.createElement('img');
                image.src = photo.sizes && photo.sizes.thumbnail ? photo.sizes.thumbnail.url : photo.url;
                image.style.maxWidth = '150px';
                row.querySelector('.bcst-person-preview').replaceChildren(image);
            });
            frame.open();
        }
    });
}());
