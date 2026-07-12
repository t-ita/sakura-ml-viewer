// XSS対策(DESIGN.md §7.2): 本文はプレーンテキストとして扱い、dangerouslySetInnerHTMLは使わない。
// https?://で始まる部分だけを正規表現で抽出し、Reactの<a>要素として個別に構築する。
const URL_SPLIT_PATTERN = /(https?:\/\/[^\s<>"']+)/g

interface ArticleBodyProps {
  text: string
}

export function ArticleBody({ text }: ArticleBodyProps) {
  const parts = text.split(URL_SPLIT_PATTERN)

  return (
    <div className="text-sm leading-relaxed break-words whitespace-pre-wrap">
      {parts.map((part, i) => {
        if (part.startsWith('http://') || part.startsWith('https://')) {
          return (
            <a
              key={i}
              href={part}
              target="_blank"
              rel="noopener noreferrer"
              className="text-primary underline underline-offset-2 hover:no-underline"
            >
              {part}
            </a>
          )
        }
        return <span key={i}>{part}</span>
      })}
    </div>
  )
}
