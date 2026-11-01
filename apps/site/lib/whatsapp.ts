// Agency-confirmed number, already displayed on the public site.
// International digits only: country code 212 + local number without its initial zero.
export const WHATSAPP_NUMBER = '212666333642';
export type ContactLanguage = 'fr' | 'en' | 'es' | 'ar';
const general = {
 fr: 'Bonjour Ben Sbih Rental Car, je souhaite réserver une voiture à Tanger.',
 en: 'Hello Ben Sbih Rental Car, I would like to rent a car in Tangier.',
 es: 'Hola Ben Sbih Rental Car, me gustaría alquilar un coche en Tánger.',
 ar: 'مرحباً Ben Sbih Rental Car، أريد استئجار سيارة في طنجة.'
};
const carMessage = {
 fr: (name:string) => `Bonjour Ben Sbih Rental Car, je suis intéressé par ${name}.`,
 en: (name:string) => `Hello Ben Sbih Rental Car, I am interested in ${name}.`,
 es: (name:string) => `Hola Ben Sbih Rental Car, estoy interesado en ${name}.`,
 ar: (name:string) => `مرحباً Ben Sbih Rental Car، أنا مهتم بـ ${name}.`
};
export const whatsappLabels = {
 fr: {general:'Contacter sur WhatsApp',car:'Réserver via WhatsApp'},
 en: {general:'Contact us on WhatsApp',car:'Book via WhatsApp'},
 es: {general:'Contactar por WhatsApp',car:'Reservar por WhatsApp'},
 ar: {general:'تواصل معنا عبر واتساب',car:'الحجز عبر واتساب'}
};
export function whatsappMessage(language:ContactLanguage,carName?:string){
 return carName ? carMessage[language](carName) : general[language];
}
export function whatsappHref(language:ContactLanguage,carName?:string){
 return 'https://wa.me/'+WHATSAPP_NUMBER+'?text='+encodeURIComponent(whatsappMessage(language,carName));
}
