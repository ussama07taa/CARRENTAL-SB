export type CalendarPeriod = {
  id: string;
  name: string;
  category: 'school' | 'national' | 'religious';
  calendar: string;
  starts_on: string;
  ends_on: string;
  reference: string;
  source: string;
  sourceLabel: string;
};

// Reviewed against the original ministry document, annex 1 (PDF page 9).
// Do not extrapolate school dates or convert lunar dates into official civil dates.
export const schoolSource = 'https://www.men.gov.ma/sites/default/files/2026-09/%D9%85%D9%82%D8%B1%D8%B1%20047.26.pdf#page=9';
export const nationalSource = 'https://www.maroc.ma/fr/le-maroc/fetes-nationales-et-religieuses';
export const calendarReviewedOn = '19/10/2026';

const school: CalendarPeriod[] = [
  ['break-1', 'Vacances intermédiaires 1', '2026-10-18', '2026-10-25'],
  ['break-2', 'Vacances intermédiaires 2', '2026-12-06', '2026-12-13'],
  ['midyear', 'Vacances de mi-année', '2027-01-24', '2027-01-31'],
  ['break-3', 'Vacances intermédiaires 3', '2027-03-21', '2027-03-28'],
  ['break-4', 'Vacances intermédiaires 4', '2027-05-09', '2027-05-16'],
].map(([id, name, starts_on, ends_on]) => ({
  id: `men-2026-2027-${id}`, name, starts_on, ends_on,
  category: 'school', calendar: 'school-2026-2027',
  reference: 'Année scolaire 2026–2027 · arrêté 047.26, annexe 1',
  source: schoolSource, sourceLabel: 'Ministère de l’Éducation nationale · PDF, page 9',
}));

const nationalDates = [
  ['new-year', 'Nouvel an', '01-01'],
  ['manifesto', 'Manifeste de l’Indépendance', '01-11'],
  ['amazigh', 'Nouvel an amazigh', '01-14'],
  ['labour', 'Fête du Travail', '05-01'],
  ['throne', 'Fête du Trône', '07-30'],
  ['oued-eddahab', 'Oued Eddahab', '08-14'],
  ['revolution', 'Révolution du Roi et du Peuple', '08-20'],
  ['youth', 'Fête de la Jeunesse', '08-21'],
  ['unity', 'Fête de l’Unité', '10-31'],
  ['green-march', 'Marche verte', '11-06'],
  ['independence', 'Fête de l’Indépendance', '11-18'],
];

const religiousDates = [
  ['fitr', 'Aïd Al-Fitr', 'Fête : 1 Chawal. Congé scolaire 2026–2027 : du 29 Ramadan au 2 Chawal 1448.'],
  ['adha', 'Aïd Al-Adha', 'Fête : 10 Dhou Al-Hijja. Congé scolaire 2026–2027 : du 9 au 11 Dhou Al-Hijja 1448.'],
  ['moharram', 'Nouvel an de l’Hégire', '1 Moharram. Calendrier scolaire 2026–2027 : 1 Moharram 1449.'],
  ['mawlid', 'Aïd Al-Mawlid', '12 Rabia Al-Awal.'],
];

export const calendarOptions = [
  {id: 'school-2026-2027', label: 'Vacances scolaires · 2026–2027'},
  {id: 'national-2026', label: 'Fêtes nationales · 2026'},
  {id: 'national-2027', label: 'Fêtes nationales · 2027'},
  {id: 'religious', label: 'Fêtes religieuses · dates à confirmer'},
];

export const calendarPeriods: CalendarPeriod[] = [
  ...school,
  ...[2026, 2027].flatMap(year => nationalDates.map(([id, name, date]) => ({
    id: `national-${year}-${id}`, name: `${name} ${year}`,
    category: 'national' as const, calendar: `national-${year}`,
    starts_on: `${year}-${date}`, ends_on: `${year}-${date}`,
    reference: 'Fête nationale · date civile fixe',
    source: nationalSource, sourceLabel: 'Portail national Maroc.ma',
  }))),
  ...religiousDates.map(([id, name, reference]) => ({
    id: `religious-${id}`, name, reference,
    category: 'religious' as const, calendar: 'religious', starts_on: '', ends_on: '',
    source: id === 'mawlid' ? nationalSource : schoolSource,
    sourceLabel: id === 'mawlid' ? 'Portail national Maroc.ma' : 'Ministère de l’Éducation nationale · PDF, page 9',
  })),
];

export const calendarPeriod = (id?: string) => calendarPeriods.find(period => period.id === id);
