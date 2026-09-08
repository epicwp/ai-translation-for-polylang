export const getLanguageData = (langSlug) => {
  // Get languages from pllat global variable
  const languages = window.pllat?.languages || [];
  return languages.find((language) => langSlug === language.slug);
};
