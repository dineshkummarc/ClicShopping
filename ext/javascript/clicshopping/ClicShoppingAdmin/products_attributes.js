(function () {
  // Tab 2 : activer jscolor (class="color") sur les inputs value_name quand le type est color_picker
  document.querySelectorAll('select[name="option_id"]').forEach(function (select) {
    function toggleJscolor() {
      var opt = select.options[select.selectedIndex];
      var type = opt ? opt.getAttribute('data-type') : '';
      var form = select.closest('form');
      if (!form) return;
      form.querySelectorAll('input[name^="value_name"]').forEach(function (input) {
        if (type === 'color_picker') {
          if (!input.classList.contains('color')) {
            input.classList.add('color');
            if (typeof jscolor !== 'undefined') jscolor.bind();
          }
        } else {
          input.classList.remove('color');
        }
      });
    }
    select.addEventListener('change', toggleJscolor);
    toggleJscolor();
  });

  // Tab 3 : aperçu couleur (swatch) à côté de values_id quand le type est color_picker
  document.querySelectorAll('select[name="options_id"]').forEach(function (optSelect) {
    var row = optSelect.closest('tr');
    if (!row) return;
    var valSelect = row.querySelector('select[name="values_id"]');
    if (!valSelect) return;

    function updateSwatch() {
      var opt = optSelect.options[optSelect.selectedIndex];
      var type = opt ? opt.getAttribute('data-type') : '';
      var swatch = valSelect.parentNode.querySelector('.attr-color-swatch');

      if (type === 'color_picker') {
        if (!swatch) {
          swatch = document.createElement('span');
          swatch.className = 'attr-color-swatch';
          swatch.style.cssText = 'display:inline-block;width:22px;height:22px;border:1px solid #999;border-radius:3px;vertical-align:middle;margin-left:6px;';
          valSelect.after(swatch);
        }
        function refreshSwatch() {
          var hex = '#' + valSelect.options[valSelect.selectedIndex].text.replace(/^#/, '');
          swatch.style.backgroundColor = hex;
        }
        valSelect.addEventListener('change', refreshSwatch);
        refreshSwatch();
      } else {
        if (swatch) swatch.remove();
      }
    }
    optSelect.addEventListener('change', updateSwatch);
    updateSwatch();
  });
}());