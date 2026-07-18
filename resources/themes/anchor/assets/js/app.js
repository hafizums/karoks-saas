import { registerKaroksPlayer } from './karoks/player.js';
import { registerKaroksEditor } from './karoks/editor.js';
import { registerKaroksProcessing } from './karoks/processing.js';

document.addEventListener('alpine:init', () => {
  registerKaroksPlayer(window.Alpine);
  registerKaroksEditor(window.Alpine);
  registerKaroksProcessing(window.Alpine);
});

window.demoButtonClickMessage = function (event) {
  event.preventDefault();
  new FilamentNotification()
    .title('Modify this button in your theme folder')
    .icon('heroicon-o-pencil-square')
    .iconColor('info')
    .send();
};
