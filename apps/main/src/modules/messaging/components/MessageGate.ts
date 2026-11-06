/**
 * AH-119 Stage B — renders its slot only when `when` is true. When false it renders an EMPTY TEXT
 * node, not the `<!--v-if-->` comment a template `v-if` leaves behind, so a thread rendered with
 * Stage B off has exactly the DOM it had before the feature existed (native polarity).
 */

import { type FunctionalComponent, createTextVNode } from 'vue'

export const MessageGate: FunctionalComponent<{ when: boolean }> = (props, { slots }) =>
  props.when ? (slots.default?.() ?? createTextVNode('')) : createTextVNode('')
MessageGate.props = ['when']

export default MessageGate
