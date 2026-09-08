import LanguageProgress from "../LanguageProgress";

const LanguageProgressList = ({ languageStats }) => {
  return (
    <div
      className="pllat-space-y-3 pllat-mb-4 pllat-flex-grow pllat-overflow-y-auto"
      style={{ maxHeight: "280px" }}
    >
      {Object.entries(languageStats).map(([languageCode, stats]) => (
        <LanguageProgress
          key={languageCode}
          code={languageCode}
          translated={stats.translated}
          total={stats.total}
        />
      ))}
    </div>
  );
};

export default LanguageProgressList;
