/**
 * PL-2.5 — thrown by a save that the person called off at a confirmation (the "step is not complete" question): the
 * step dialog stays open and shows no error, because nothing failed.
 */
export class SaveCancelled extends Error {
  constructor() {
    super('save cancelled')
    this.name = 'SaveCancelled'
  }
}
