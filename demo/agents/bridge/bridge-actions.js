import { registerActions as registerPolicy } from '../policy-action.js';

export function registerActions(actions) {
    registerPolicy(actions, { bridge: true });
}
