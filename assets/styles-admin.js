(function () {
	var preview = document.getElementById('cm-style-preview');
	if (!preview) {
		return;
	}

	var ids = {
		itemGap: 'cm-style-item-gap',
		cardGap: 'cm-style-card-gap',
		collectionText: 'cm-style-collection-text',
		cardText: 'cm-style-card-text',
		textLayout: 'cm-style-text-layout',
		titleSize: 'cm-style-title-size',
		countSize: 'cm-style-count-size',
		cardTitleSize: 'cm-style-card-title-size',
		radius: 'cm-style-radius',
		width: 'cm-style-width',
		shadow: 'cm-style-shadow',
		shadowFields: 'cm-style-shadow-fields',
		offsetX: 'cm-style-shadow-x',
		offsetY: 'cm-style-shadow-y',
		blur: 'cm-style-shadow-blur',
		spread: 'cm-style-shadow-spread',
		panelRadius: 'cm-style-panel-radius',
		panelPadding: 'cm-style-panel-padding',
	};

	function el(key) {
		return document.getElementById(ids[key]);
	}

	function px(field, fallback) {
		return (parseInt(field && field.value, 10) || fallback || 0) + 'px';
	}

	function resolveColor(id, fallback) {
		var input = document.getElementById(id);
		if (!input) {
			return fallback;
		}
		var value = (input.value || '').trim();
		var control = input.closest('[data-color-control]');
		if (!control) {
			return value || fallback;
		}
		var selected = control.querySelector('.cm-color__option.is-selected');
		if (!selected) {
			return value || fallback;
		}
		var stored = selected.getAttribute('data-color') || '';
		if (stored === '') {
			return fallback;
		}
		if (stored === '__custom') {
			return value || fallback;
		}
		return selected.getAttribute('data-hex') || value || fallback;
	}

	function replaceModifier(prefix, value) {
		var classes = preview.className.split(/\s+/).filter(function (name) {
			return name && name.indexOf(prefix) !== 0;
		});
		classes.push(prefix + value);
		preview.className = classes.join(' ');
	}

	function updatePreview() {
		var y = parseInt(el('offsetY').value, 10) || 0;
		var hoverY = y + (y >= 0 ? 4 : -4);
		var showShadow = el('shadow').checked;

		preview.style.setProperty('--cm-item-gap', px(el('itemGap'), 14));
		preview.style.setProperty('--cm-card-gap', px(el('cardGap'), 12));
		preview.style.setProperty('--cm-title-size', px(el('titleSize'), 18));
		preview.style.setProperty('--cm-count-size', px(el('countSize'), 12));
		preview.style.setProperty('--cm-card-title-size', px(el('cardTitleSize'), 15));
		preview.style.setProperty('--cm-tile-radius', px(el('radius'), 12));
		preview.style.setProperty('--cm-border-width', px(el('width'), 0));
		preview.style.setProperty('--cm-border-color', resolveColor('cm-style-border-color', 'currentColor'));
		preview.style.setProperty('--cm-shadow-x', px(el('offsetX'), 0));
		preview.style.setProperty('--cm-shadow-y', y + 'px');
		preview.style.setProperty('--cm-shadow-blur', px(el('blur'), 24));
		preview.style.setProperty('--cm-shadow-spread', px(el('spread'), 0));
		preview.style.setProperty(
			'--cm-shadow-color',
			resolveColor('cm-style-shadow-color', '#00000061')
		);
		preview.style.setProperty('--cm-shadow-hover-y', hoverY + 'px');
		preview.style.setProperty('--cm-panel-radius', px(el('panelRadius'), 16));
		preview.style.setProperty('--cm-panel-padding', px(el('panelPadding'), 18));
		preview.style.setProperty(
			'--cm-panel-bg',
			resolveColor('cm-style-panel-bg', 'color-mix(in srgb, currentColor 8%, transparent)')
		);
		preview.style.setProperty(
			'--cm-nav-bg',
			resolveColor('cm-style-nav-bg', 'color-mix(in srgb, #000 72%, transparent)')
		);
		preview.style.setProperty('--cm-nav-color', resolveColor('cm-style-nav-color', '#fff'));

		preview.classList.toggle('cm-browser--shadow', showShadow);
		preview.classList.toggle('cm-browser--no-shadow', !showShadow);
		replaceModifier('cm-browser--collection-text-', el('collectionText').value || 'overlay');
		replaceModifier('cm-browser--card-text-', el('cardText').value || 'overlay');
		replaceModifier('cm-browser--text-', el('textLayout').value || 'stack');

		var shadowFields = el('shadowFields');
		if (shadowFields) {
			shadowFields.hidden = !showShadow;
		}
	}

	function bindColorControl(control) {
		var valueInput = control.querySelector('input[type="hidden"]');
		var toggle = control.querySelector('.cm-color__toggle');
		var menu = control.querySelector('.cm-color__menu');
		var options = control.querySelectorAll('.cm-color__option');
		var hexInput = control.querySelector('.cm-color__hex');
		var picker = control.querySelector('.cm-color__picker');
		var custom = control.querySelector('.cm-color__custom');
		var chip = control.querySelector('.cm-color__toggle .cm-color__chip');
		var label = control.querySelector('.cm-color__label');

		function expandHex(value) {
			if (value && value.length === 4 && value.indexOf('#') === 0) {
				return '#' + value[1] + value[1] + value[2] + value[2] + value[3] + value[3];
			}
			return value;
		}

		function findOption(value, mode) {
			var key = mode === 'custom' || (value && value.indexOf('#') === 0) ? '__custom' : value;
			return (
				Array.prototype.find.call(options, function (option) {
					return option.getAttribute('data-color') === key;
				}) || options[0]
			);
		}

		function setColor(value, mode) {
			var option = findOption(value, mode);
			var isCustom = option && option.getAttribute('data-color') === '__custom';
			valueInput.value = isCustom ? value : option ? option.getAttribute('data-color') : '';
			options.forEach(function (item) {
				item.classList.toggle('is-selected', item === option);
			});
			if (label && option) {
				label.textContent = option.getAttribute('data-label') || option.textContent;
			}
			if (chip) {
				chip.style.background = isCustom
					? value || '#000000'
					: option.getAttribute('data-hex') || 'currentColor';
			}
			if (custom) {
				custom.hidden = !isCustom;
			}
			if (hexInput && mode !== 'hex' && isCustom) {
				hexInput.value = value.indexOf('#') === 0 ? value : '';
			}
			if (picker && value && value.indexOf('#') === 0) {
				picker.value = expandHex(value);
			}
			updatePreview();
		}

		function closeMenu() {
			if (menu) {
				menu.hidden = true;
			}
			if (toggle) {
				toggle.setAttribute('aria-expanded', 'false');
			}
		}

		if (toggle && menu) {
			toggle.addEventListener('click', function () {
				var open = menu.hidden;
				document.querySelectorAll('.cm-color__menu').forEach(function (other) {
					other.hidden = true;
				});
				menu.hidden = !open;
				toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			});
		}

		options.forEach(function (option) {
			option.addEventListener('click', function () {
				var next = option.getAttribute('data-color') || '';
				if (next === '__custom') {
					setColor(hexInput && hexInput.value ? hexInput.value : picker ? picker.value : '#000000', 'custom');
				} else {
					setColor(next);
				}
				closeMenu();
			});
		});

		if (hexInput) {
			hexInput.addEventListener('input', function () {
				var value = hexInput.value.trim();
				if (!value || /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(value)) {
					setColor(value, 'hex');
				}
			});
		}

		if (picker) {
			picker.addEventListener('input', function () {
				setColor(picker.value, 'custom');
			});
		}

		document.addEventListener('click', function (event) {
			if (!control.contains(event.target)) {
				closeMenu();
			}
		});

		setColor(valueInput.value, valueInput.value.indexOf('#') === 0 ? 'custom' : '');
	}

	document.querySelectorAll('[data-color-control]').forEach(bindColorControl);

	Object.keys(ids).forEach(function (key) {
		var field = el(key);
		if (field) {
			field.addEventListener('input', updatePreview);
			field.addEventListener('change', updatePreview);
		}
	});

	updatePreview();
})();
