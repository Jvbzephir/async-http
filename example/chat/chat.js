/*
 * This file is part of KoolKode Async Http.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

window.onload = function() {

	function logMessage(message) {
		var el = document.createElement('pre');
		el.appendChild(document.createTextNode(message));
		document.getElementById('websocket-log').appendChild(el);
	}

	var body = document.getElementsByTagName('body')[0];
	var ws = new WebSocket('ws://localhost:8080/chat.sock', 'chat-example');

	ws.onopen = function() {
		logMessage('WebSocket connection established [' + ws.protocol + ']');

		ws.onmessage = function(message) {
			var data = JSON.parse(message.data);

			if (data.type == 'user') {
				logMessage('RECEIVED: <' + data.message + '>');
			}
		};

		ws.onclose = function() {
			logMessage('WebSocket connection closed by server');
		};

		var form = document.getElementById('message-form');

		form.onsubmit = function() {
			var field = document.getElementById('message');
			var text = field.value;

			if (text !== undefined && text !== null && text !== '') {
				field.value = '';

				ws.send(text);
			}

			return false;
		};
	};
};
